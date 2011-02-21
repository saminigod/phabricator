<?php

/*
 * Copyright 2011 Facebook, Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

class PhabricatorOAuthLoginController extends PhabricatorAuthController {

  private $provider;
  private $userID;
  private $accessToken;

  public function shouldRequireLogin() {
    return false;
  }

  public function willProcessRequest(array $data) {
    $this->provider = PhabricatorOAuthProvider::newProvider($data['provider']);
  }

  public function processRequest() {
    $current_user = $this->getRequest()->getUser();
    if ($current_user->getPHID()) {
      // If we're already logged in, ignore everything going on here. TODO:
      // restore account linking.
      return id(new AphrontRedirectResponse())->setURI('/');
    }

    $provider = $this->provider;
    if (!$provider->isProviderEnabled()) {
      return new Aphront400Response();
    }

    $request = $this->getRequest();

    if ($request->getStr('error')) {
      $error_view = id(new PhabricatorOAuthFailureView())
        ->setRequest($request);
      return $this->buildErrorResponse($error_view);
    }

    $token = $request->getStr('token');
    if (!$token) {
      $client_id        = $provider->getClientID();
      $client_secret    = $provider->getClientSecret();
      $redirect_uri     = $provider->getRedirectURI();
      $auth_uri         = $provider->getTokenURI();

      $code = $request->getStr('code');
      $query_data = array(
        'client_id'     => $client_id,
        'client_secret' => $client_secret,
        'redirect_uri'  => $redirect_uri,
        'code'          => $code,
      );

      $stream_context = stream_context_create(
        array(
          'http' => array(
            'method'  => 'POST',
            'header'  => 'Content-type: application/x-www-form-urlencoded',
            'content' => http_build_query($query_data),
          ),
        ));

      $stream = fopen($auth_uri, 'r', false, $stream_context);

      $meta = stream_get_meta_data($stream);
      $response = stream_get_contents($stream);

      fclose($stream);

      if ($response === false) {
        return $this->buildErrorResponse(new PhabricatorOAuthFailureView());
      }

      $data = array();
      parse_str($response, $data);

      $token = idx($data, 'access_token');
      if (!$token) {
        return $this->buildErrorResponse(new PhabricatorOAuthFailureView());
      }
    }

    $userinfo_uri = new PhutilURI($provider->getUserInfoURI());
    $userinfo_uri->setQueryParams(
      array(
        'access_token' => $token,
      ));

    $user_json = @file_get_contents($userinfo_uri);
    $user_data = json_decode($user_json, true);

    $this->accessToken = $token;

    switch ($provider->getProviderKey()) {
      case PhabricatorOAuthProvider::PROVIDER_GITHUB:
        $user_data = $user_data['user'];
        break;
    }
    $this->userData = $user_data;

    $user_id = $this->retrieveUserID();

    // Login with known auth.

    $known_oauth = id(new PhabricatorUserOAuthInfo())->loadOneWhere(
      'oauthProvider = %s and oauthUID = %s',
      $provider->getProviderKey(),
      $user_id);
    if ($known_oauth) {
      $known_user = id(new PhabricatorUser())->load($known_oauth->getUserID());
      $session_key = $known_user->establishSession('web');
      $request->setCookie('phusr', $known_user->getUsername());
      $request->setCookie('phsid', $session_key);
      return id(new AphrontRedirectResponse())
        ->setURI('/');
    }

    // Merge accounts based on shared email. TODO: should probably get rid of
    // this.

    $oauth_email = $this->retrieveUserEmail();
    if ($oauth_email) {
      $known_email = id(new PhabricatorUser())
        ->loadOneWhere('email = %s', $oauth_email);
      if ($known_email) {
        $known_oauth = id(new PhabricatorUserOAuthInfo())->loadOneWhere(
          'userID = %d AND oauthProvider = %s',
          $known_email->getID(),
          $provider->getProviderKey());
        if ($known_oauth) {
          $provider_name = $provider->getName();
          throw new Exception(
            "The email associated with the ".$provider_name." account you ".
            "just logged in with is already associated with another ".
            "Phabricator account which is, in turn, associated with a ".
            $provider_name." account different from the one you just logged ".
            "in with.");
        }

        $oauth_info = new PhabricatorUserOAuthInfo();
        $oauth_info->setUserID($known_email->getID());
        $oauth_info->setOAuthProvider($provider->getProviderKey());
        $oauth_info->setOAuthUID($user_id);
        $oauth_info->save();

        $session_key = $known_email->establishSession('web');
        $request->setCookie('phusr', $known_email->getUsername());
        $request->setCookie('phsid', $session_key);
        return id(new AphrontRedirectResponse())
          ->setURI('/');
      }
    }

    $errors = array();
    $e_username = true;
    $e_email = true;
    $e_realname = true;

    $user = new PhabricatorUser();

    $suggestion = $this->retrieveUsernameSuggestion();
    $user->setUsername($suggestion);

    $oauth_realname = $this->retreiveRealNameSuggestion();

    if ($request->isFormPost()) {

      $user->setUsername($request->getStr('username'));
      $username = $user->getUsername();
      $matches = null;
      if (!strlen($user->getUsername())) {
        $e_username = 'Required';
        $errors[] = 'Username is required.';
      } else if (!preg_match('/^[a-zA-Z0-9]+$/', $username, $matches)) {
        $e_username = 'Invalid';
        $errors[] = 'Username may only contain letters and numbers.';
      } else {
        $e_username = null;
      }

      if ($oauth_email) {
        $user->setEmail($oauth_email);
      } else {
        $user->setEmail($request->getStr('email'));
        if (!strlen($user->getEmail())) {
          $e_email = 'Required';
          $errors[] = 'Email is required.';
        } else {
          $e_email = null;
        }
      }

      if ($oauth_realname) {
        $user->setRealName($oauth_realname);
      } else {
        $user->setRealName($request->getStr('realname'));
        if (!strlen($user->getStr('realname'))) {
          $e_realname = 'Required';
          $errors[] = 'Real name is required.';
        } else {
          $e_realname = null;
        }
      }

      if (!$errors) {
        $image = $this->retreiveProfileImageSuggestion();
        if ($image) {
          $file = PhabricatorFile::newFromFileData(
            $image,
            array(
              'name' => $provider->getProviderKey().'-profile.jpg'
            ));
          $user->setProfileImagePHID($file->getPHID());
        }

        try {
          $user->save();

          $oauth_info = new PhabricatorUserOAuthInfo();
          $oauth_info->setUserID($user->getID());
          $oauth_info->setOAuthProvider($provider->getProviderKey());
          $oauth_info->setOAuthUID($user_id);
          $oauth_info->save();

          $session_key = $user->establishSession('web');
          $request->setCookie('phusr', $user->getUsername());
          $request->setCookie('phsid', $session_key);
          return id(new AphrontRedirectResponse())->setURI('/');
        } catch (AphrontQueryDuplicateKeyException $exception) {
          $key = $exception->getDuplicateKey();
          if ($key == 'userName') {
            $e_username = 'Duplicate';
            $errors[] = 'That username is not unique.';
          } else if ($key == 'email') {
            $e_email = 'Duplicate';
            $errors[] = 'That email is not unique.';
          } else {
            throw $exception;
          }
        }
      }
    }

    $error_view = null;
    if ($errors) {
      $error_view = new AphrontErrorView();
      $error_view->setTitle('Registration Failed');
      $error_view->setErrors($errors);
    }

    $form = new AphrontFormView();
    $form
      ->addHiddenInput('token', $token)
      ->setUser($request->getUser())
      ->setAction($provider->getRedirectURI())
      ->appendChild(
        id(new AphrontFormTextControl())
          ->setLabel('Username')
          ->setName('username')
          ->setValue($user->getUsername())
          ->setError($e_username));

    if (!$oauth_email) {
      $form->appendChild(
        id(new AphrontFormTextControl())
          ->setLabel('Email')
          ->setName('email')
          ->setValue($request->getStr('email'))
          ->setError($e_email));
    }

    if (!$oauth_realname) {
      $form->appendChild(
        id(new AphrontFormTextControl())
          ->setLabel('Real Name')
          ->setName('realname')
          ->setValue($request->getStr('realname'))
          ->setError($e_realname));
    }

    $form
      ->appendChild(
        id(new AphrontFormSubmitControl())
          ->setValue('Create Account'));

    $panel = new AphrontPanelView();
    $panel->setHeader('Create New Account');
    $panel->setWidth(AphrontPanelView::WIDTH_FORM);
    $panel->appendChild($form);

    return $this->buildStandardPageResponse(
      array(
        $error_view,
        $panel,
      ),
      array(
        'title' => 'Create New Account',
      ));
  }

  private function buildErrorResponse(PhabricatorOAuthFailureView $view) {
    $provider = $this->provider;

    $provider_name = $provider->getProviderName();
    $view->setOAuthProvider($provider);

    return $this->buildStandardPageResponse(
      $view,
      array(
        'title' => $provider_name.' Auth Failed',
      ));
  }

  private function retrieveUserID() {
    return $this->userData['id'];
  }

  private function retrieveUserEmail() {
    return $this->userData['email'];
  }

  private function retrieveUsernameSuggestion() {
    switch ($this->provider->getProviderKey()) {
      case PhabricatorOAuthProvider::PROVIDER_FACEBOOK:
        $matches = null;
        $link = $this->userData['link'];
        if (preg_match('@/([a-zA-Z0-9]+)$@', $link, $matches)) {
          return $matches[1];
        }
        break;
      case PhabricatorOAuthProvider::PROVIDER_GITHUB:
        return $this->userData['login'];
    }
    return null;
  }

  private function retreiveProfileImageSuggestion() {
    switch ($this->provider->getProviderKey()) {
      case PhabricatorOAuthProvider::PROVIDER_FACEBOOK:
        $uri = 'https://graph.facebook.com/me/picture?access_token=';
        return @file_get_contents($uri.$this->accessToken);
      case PhabricatorOAuthProvider::PROVIDER_GITHUB:
        $id = $this->userData['gravatar_id'];
        if ($id) {
          $uri = 'http://www.gravatar.com/avatar/'.$id.'?s=50';
          return @file_get_contents($uri);
        }
    }
    return null;
  }

  private function retreiveRealNameSuggestion() {
    return $this->userData['name'];
  }

}
