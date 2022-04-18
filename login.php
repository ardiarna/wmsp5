<?php
require_once __DIR__ . '/vendor/autoload.php';
// Start the session
SessionUtils::sessionStart();

if (SessionUtils::isAuthenticated()) {
  // redirect to main page.
  $location = dirname(HttpUtils::fullUrl($_SERVER));
  header("Location: $location");
  exit;
}

function authUser($username, $password)
{
  $db = PostgresqlDatabase::getInstance();

  $sql = '
SELECT gua_kode, gua_nama, gua_subplants, array_to_string(gua_lvl, \',\') AS roles, gua_subplant_handover, gua_active 
FROM gen_user_adm WHERE gua_kode = $1 AND gua_pass = crypt($2, gua_pass)
';
  $result = $db->parameterizedQuery($sql, array($username, $password));
  $user = pg_fetch_object($result);
  $db->close();

  return $user;
}

if (isset($_POST['Log'])) {
  $username = $_POST['logusername'];
  $password = $_POST['logpassword'];
  $user = authUser($username, $password);

  if (is_object($user)) {
    $_SESSION["usernm"] = $username;
    if (isset($_SESSION['loginError'])) {
      unset($_SESSION['loginError']);
    }

    $user->gua_active = QueryResultConverter::toBool($user->gua_active);
    $user->gua_subplants = explode(',', $user->gua_subplants);
    $user->roles = explode(',', $user->roles);
    $user->gua_subplant_handover = explode(',', $user->gua_subplant_handover);

    if (!$user->gua_active) {
      $_SESSION['loginError_active'] = $user->gua_kode;
    } else {
      // redirect user.
      if (isset($_SESSION['loginError_active'])) {
        unset($_SESSION['loginError_active']);
      }

      $_SESSION['user'] = $user;
      if (UserRole::isSuperuser()) {
        $_SESSION['superuser'] = true;
      }
      // TODO remove this, move to session.
      setcookie('userid', $user->gua_kode, time() + 60 * 60 * 24 * 30);

      $origin = dirname(HttpUtils::fullUrl($_SERVER));
      header("Location: $origin");
      exit;
    }
  } else {
    $_SESSION['loginError'] = true;
  }
}


?>
<!DOCTYPE html>
<html lang="id">
<head>
  <title>Login Form</title>
  <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
  <meta name="viewport" content="width=device-width">
  <link rel="stylesheet" href="assets/libs/bootstrap/css/bootstrap.min.css"/>
  <link rel="stylesheet" href="assets/libs/bootstrap/css/bootstrap-theme.min.css"/>
  <link rel="stylesheet" href="assets/libs/bootstrap-validator/bootstrapValidator.min.css"/>

  <script src="assets/libs/jquery/jquery.min.js"></script>
  <script src="assets/libs/bootstrap-validator/bootstrapValidator.min.js"></script>
  <script src="assets/libs/bootstrap/js/bootstrap.min.js"></script>
  <script type="text/javascript">
    "use strict";
    $(function () {
      $(".btn").click(function () {
        $(this).button('loading').delay(1000).queue(function () {
          $(this).button('reset');
          $(this).dequeue();
        });
      });
    });
  </script>

  <style type="text/css">
    div.middle {
      margin: 20px 10px 5px 10px; /*top right bottom left */
    }

    span.middle {
      margin: 50px 70px 10px 0; /*top right bottom left */
    }

    .save_button {
      min-width: 80px;
      max-width: 80px;
    }
  </style>
</head>
<body>
<div class="container">
  <div id="loginbox" style="margin-top:50px;" class="mainbox col-md-5 col-md-offset-3 col-sm-8 col-sm-offset-2">
    <div class="panel panel-primary">
      <div class="panel-heading">
        <div class="panel-title">Login</div>
      </div>
      <div style="padding-top:30px" class="panel-body">
        <form class="form-horizontal regform" id="regform" role="form" method=post>
          <div style="display:none" id="login-alert" class="alert alert-danger col-sm-12"></div>
          <div style="margin-bottom: 10px" class="input-group">
            <span class="input-group-addon"><i class="glyphicon glyphicon-user"></i></span>
            <input type="hidden" name="Log" value="1">
            <input id="logusername" type="text" class="form-control" name="logusername" value=""
                   placeholder="Kode User ">
          </div>
          <div style="margin-bottom: 10px" class="input-group">
            <span class="input-group-addon"><i class="glyphicon glyphicon-lock"></i></span>
            <input id="logpassword" type="password" class="form-control" name="logpassword"
                   placeholder="Password">
          </div>
          <div style="margin-top:10px" class="form-group">
            <!-- Button -->
            <div class="col-sm-offset-0 col-xs-12">
              <button type="submit" name="masuk" class="btn btn-success save_button"
                      data-loading-text="Loading...">Masuk
              </button>
              <button type="button" name="batal" class="btn btn-danger save_button"
                      onClick='window.location="login.php"' data-loading-text="Loading...">Batal
              </button>
            </div>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>
<script>
  "use strict";

  $(document).ready(function () {
    $('.regform').bootstrapValidator({
      message: 'This value is not valid',
      excluded: [':disabled', ':hidden', ':not(:visible)'],
      feedbackIcons: {
        valid: 'glyphicon glyphicon-ok',
        invalid: 'glyphicon glyphicon-remove',
        validating: 'glyphicon glyphicon-refresh'
      }, live: 'enabled',
      submitButtons: 'button[type="submit"]',
      trigger: null,
      fields: {
        logusername: {
          validators: {
            notEmpty: {
              message: 'Kode User Harap di isi'
            }
          }
        },
        logpassword: {
          validators: {
            notEmpty: {
              message: 'Password Harap di isi'
            }
          }
        }
      }
    });
  });
  <?php if(isset($_SESSION['loginError']) && $_SESSION['loginError']): ?>
  window.alert('Username/password anda salah!');
  <?php endif ?>
</script>
<!--Add Header, Main Content and Footer here-->
</body>
</html>
