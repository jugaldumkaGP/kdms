<?php

declare(strict_types=1);

$debug = false;

require_once dirname(__DIR__) . '/includes/kdms_log.php';
kdms_log_bootstrap();

session_start();

$config_data = include_once '../site_config.php';
include_once '../Logic/clsAdminTasks.php';

$loginID = '';
$password = '';
$role = '';
$name = '';
$email = '';
$phone = '';
$access = '';
$message = '';

// GET / no-credentials path: decide whether to show the login form or redirect.
if (empty($_POST['loginID'])) {
    if (session_status() === PHP_SESSION_ACTIVE && ! empty($_SESSION['LoginID']) && ! empty($_SESSION['Role'])) {
        // User is already authenticated — redirect to the main app instead of destroying the session.
        // This prevents accidental logout when the browser navigates back to login.php.
        $url = $config_data['webroot'] . 'UI/index.php';
        header('Location: ' . $url);
        exit;
    }
    // No valid session — clear any partial/stale session data before showing the login form.
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_unset();
        session_destroy();
    }
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

$requestData = $_POST;
unset($_POST);

// POST login attempt: must finish (redirect or error) before any HTML — otherwise header() fails.
if (!empty($requestData['loginID'])) {
    $response = array();
    $adminTasks = new clsAdminTasks($requestData);
    $response = $adminTasks->processAdminTasks();

    if ($debug) {
        echo 'fetched the login data <br>';
        var_dump($response);
        exit;
    }

    if (!empty($response['User_Key'])) {
        $loginID = urldecode($response['User_Key']);
    }
    if (!empty($response['User_Name'])) {
        $name = urldecode($response['User_Name']);
    }
    if (!empty($response['User_Role'])) {
        $role = urldecode($response['User_Role']);
    }
    if (!empty($response['User_Email'])) {
        $email = urldecode($response['User_Email']);
    }
    if (!empty($response['User_Phone'])) {
        $phone = urldecode($response['User_Phone']);
    }
    if (!empty($response['Access']) && $response['Access'] !== '') {
        $access = urldecode($response['Access']);
    }

    // Guard: a user without a Role cannot pass sessionCheck.php, so treat it as a login failure
    // rather than silently setting $_SESSION['Role'] = '' which would immediately log them out.
    if (!empty($loginID) && $role === '') {
        $message = 'Login failed: user account has no role assigned. Please contact the administrator.';
        $loginID = $requestData['loginID'];
        $password = $requestData['password'];
    } elseif (!empty($loginID)) {
        // Regenerate the session ID on every successful login to prevent session-fixation attacks.
        // Delete the old session file so stale data can't be replayed.
        session_regenerate_id(true);

        $_SESSION['LoginID'] = $loginID;
        $_SESSION['UserName'] = $name;
        $_SESSION['UserEmail'] = $email;
        $_SESSION['Role'] = $role;
        $_SESSION['Access'] = $access;
        include_once '../initialize.php';
        $url = $config_data['webroot'] . 'UI/index.php';
        header('Location: ' . $url);
        exit;
    }

    if ($message === '') {
        $failMsg = isset($response['message']) ? trim((string) $response['message']) : '';
        if ($failMsg !== '') {
            $message = $failMsg;
        } else {
            $message = 'Incorrect credentials!';
        }
    }
    $loginID = $requestData['loginID'];
    $password = $requestData['password'];
}
?>
<!DOCTYPE html>
<html>
<head>
  <title>
    SignIn(KDMS)
  </title>
  <?php include_once 'header.php'; ?>
</head>
<body>
  <div class="content">
      <div class="container-fluid">
            <div class="row">
              <div class="col-md-4">
              </div>
                  <div class="col-md-4">
                <div class="card">
                  <div class="card-header card-header-primary">
                    <h4 class="card-title">KDMS Login </h4>
                  </div>
                  <div class="card-body">
                      <form  id="myForm" method="post" action="login.php">
                      <div class="row">

                      </div>
                      <div class="row">
                        <div class="col-md-12">
                          <div class="form-group">
                            <label class="bmd-label-floating">Username</label>
                            <input type="text" class="form-control" name="loginID" id="loginID" value="<?= $loginID; ?>">
                          </div>
                        </div>
                      </div>

                      <div class="row">
                        <div class="col-md-12">
                          <div class="form-group">
                            <label class="bmd-label-floating">Password</label>
                            <input type="password" class="form-control" name="password" id="password" value="<?php echo $password; ?>">
                                <input type="hidden" name="type" id="type" value="login">
                          </div>
                        </div>

                      </div>
                          <div class="row">
                              <div class="col-md-12">
                                  <p class="text-danger">
                                      <?php echo $message; ?> </p>
                              </div>
                          </div>

                      <div class="row">
                        <div class="col-md-12">

                          <div class="form-group">
                            <a href="recovery.php"<label class="bmd-label-floating">Forgot password</label></a>
                          </div></div></div>

                      <button class="btn btn-success pull-right" onclick="" >SignIn</button>
                  </form>
                     <div class="clearfix"></div>
                  </div>
                </div>
              </div>
            </div>
          </div>
          </div>
            </div>
          </div>
        </div>
      </div>
      <?php include_once 'scriptJS.php'; ?>
</body>
</html>
