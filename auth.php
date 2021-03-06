<?php
/**
 * @author Dmitry Ketov <dketov@gmail.com>
 * @license http://www.gnu.org/copyleft/gpl.html GNU Public License
 * @package moodle multiauth
 *
 * Authentication Plugin: Loginza Authentication
 * see http://loginza.ru for protocol and API details
 */

if (!defined('MOODLE_INTERNAL')) {
    die('Direct access to this script is forbidden.');
}

require_once($CFG->libdir.'/authlib.php');
require_once($CFG->libdir.'/filelib.php');
require_once($CFG->dirroot.'/user/profile/lib.php');

class auth_plugin_loginza extends auth_plugin_base {

  function auth_plugin_loginza() {
    $this->authtype = 'loginza';
    $this->config = get_config('auth/loginza');
  }

  function config_form($config, $err, $user_fields) {
    include('config.html');
  }

  function process_config($config) {
    set_config('id', trim($config->id), 'auth/loginza');
    set_config('skey', trim($config->skey), 'auth/loginza');

    return true;
  }

  function loginpage_hook() {
    global $frm, $SESSION, $CFG, $DB;

    $token = (isset($_POST['token']) and 
	strpos($_SERVER['HTTP_REFERER'], 'http://loginza.ru') !== FALSE)
	? $_POST['token'] : NULL;

    if($token) {
      $url = new moodle_url('http://loginza.ru/api/authinfo', array('token' => $token));

      if(!empty($this->config->id)) {
        $url->param('id', $this->config->id);
      }

      if(!empty($this->config->skey)) {
        $url->param('sig', md5($token . $this->config->skey));
      }

      $r = download_file_content($url->out(false));
      if(!empty($r)) 
      	$d = json_decode($r);

      if(!empty($d) and !$d->error_type) {
        //authenticated !:)
        $d->token = $token;

        $frm->username = $this->username($d); // fake username 
        $frm->password = $this->password($d); // fake password
    	$SESSION->auth_plugin_loginza = $d;
      }
    }

    $CFG->nolastloggedin = true;
  }

  function username($u)
  {

    // Arggggh.... http://feedback.loginza.ru/problem/details/id/11618
    if($username = $this->vkontakteru($u)) {
        return $username;
    }
    // o_O http://forum.loginza.ru/viewtopic.php?f=12&t=411
    if($username = $this->googlecom($u)) {
        return $username;
    }
    // o_O http/https facebook
    if($username = $this->facebookcom($u)) {
        return $username;
    }

    return 'loginza-user-' . md5($u->identity);
  }

  function sync_roles($user) // HACK :)
  {
       global $SESSION;

       $user->profile_field_loginza = serialize($SESSION->auth_plugin_loginza);

       unset($SESSION->auth_plugin_loginza);
       profile_save_data($user);
  }

  function facebookcom($u)
  {
    global $DB;

    if(strpos($u->identity, 'https://www.facebook.com/') !== FALSE) {
        $identity = str_replace('https://www.facebook.com/',
                                'http://www.facebook.com/', $u->identity);

        $username = 'loginza-user-' . md5($identity);
        if($user = $DB->get_record('user', array('username' => $username))) {
            return $username;
        }
    }

    return NULL;
  }

  function googlecom($u)
  {
    global $DB;

    if(strpos($u->identity, 'https://www.google.com/accounts/o8/id') !== FALSE) {

        $username = 'loginza-user-' . md5($u->identity);
        if($user = $DB->get_record('user', array('email' => $u->email))) {
            return $user->username;
        }
        if($user = $DB->get_record('user', array('username' => $username))) {
            return $username;
        }
    }

    return NULL;
  }

  function vkontakteru($u)
  {
    global $DB;

    if(strpos($u->identity, 'http://vk.com') !== FALSE) {
        $identity = str_replace('http://vk.com', 'http://vkontakte.ru', $u->identity);

        $username = 'loginza-user-' . md5($identity);
        if($user = $DB->get_record('user', array('username' => $username))) {
            return $username;
        }
    }

    return NULL;
  }

  function password($u) {
    return md5($u->token);
  }

  function is_internal() {
    return false;
  }

  function user_login($username, $password) {
    global $SESSION;

    $d = $SESSION->auth_plugin_loginza;

    return ($username === $this->username($d)) and ($password === $this->password($d));
  }

  function get_userinfo($username) {
    global $SESSION;

    $d = $SESSION->auth_plugin_loginza;

    $r = array(
      'firstname' => $d->name->first_name,
      'lastname' => $d->name->last_name,
      'country' => $d->address->home->country,
      'lang' => $d->language,
      'email' => $d->email,
      'url' => !empty($d->web) ? $d->web->default : '',
      //'picture' => $d->photo, // FIXME: photo is URL
      //'?' => $d->dob, // date of birthday
      //'?' => $d->gender,
    );

    if($d->provider === 'http://openid.yandex.ru/server/') {
        if(preg_match('|http://openid.yandex.ru/([^/]+)/|', $d->identity, $matches)) {
            $r['email'] = $matches[1] . '@yandex.ru';
        }
    }

    return $r;
  }

  function can_change_password() {
    return true;
  }

  function change_password_url() {
    global $CFG;

    return $CFG->wwwroot . '/auth/loginza/change.php';
  }

  function loginpage_idp_list($wantsurl) {
    global $CFG;
    $idps = array();

    $idps[] = array(
                'url'  => new moodle_url('https://loginza.ru/api/widget',
                                          array('token_url' => $CFG->wwwroot . '/login/index.php')),
                'icon' => new pix_icon('sign_in_button_gray', 
                                       get_string('auth_loginzabutton', 'auth_loginza'), 'auth_loginza'),
                'name' => ''
    );

    return $idps;
  }
}
