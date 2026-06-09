<!-- Header section visible on top of every page -->

<nav class="navbar navbar-main navbar-expand-lg px-0 border-radius-xl shadow-none kdms-top-navbar" id="navbarBlur" data-scroll="true">
  
  <div class="container-fluid py-1 px-3 kdms-top-nav">
    <div class="kdms-active-event">
      <h3><?php echo $_SESSION['eventDesc']; ?></h3>
    </div>
    <nav class="navbar navbar-expand-lg navbar-transparent navbar-absolute fixed-top kdms-toggle-navbar">
      <div class="container-fluid">
        <div class="navbar-wrapper">
        </div>
        <button class="navbar-toggler" type="button" data-toggle="collapse" aria-controls="navigation-index" aria-expanded="false" aria-label="Toggle navigation">
          <span class="sr-only">Toggle navigation</span>
          <span class="navbar-toggler-icon icon-bar"></span>
          <span class="navbar-toggler-icon icon-bar"></span>
          <span class="navbar-toggler-icon icon-bar"></span>
        </button>
        <div class="collapse navbar-collapse justify-content-end">
        </div>
      </div>
    </nav>
    <div class="collapse navbar-collapse mt-sm-0 mt-2 me-md-0 me-sm-4" id="navbar">
      <div class="ms-md-auto pe-md-3 d-flex align-items-center kdms-top-nav-searchbar">
        <span class="bmd-form-group">
          <div class="input-group input-group-outline">
            <input type="text" id="search-data" name="searchData" class="form-control" placeholder="Search By Name, Phone or Station" onfocus="focused(this)" onfocusout="defocused(this)">
            <div class="scrollbar-dynamic-search" id="search-result-container" style="display:none;">
            </div>
          </div>
        </span>
      </div>
      <ul class="navbar-nav  justify-content-end kdms-navbar-items">
        <li class="nav-item d-flex align-items-center kdms-user-role">
          <h6 class="font-weight-bolder mb-0 kdms-user"><?php echo $_SESSION['UserName'], " - ", $_SESSION['Role']; ?></h6>
        </li>
        <li class="nav-item d-flex align-items-center">
          <!-- POST to logout.php — prevents accidental GET-based session destruction -->
          <form method="post" action="logout.php" style="margin:0;padding:0;">
            <button type="submit" class="nav-link font-weight-bold px-0 text-body"
                    style="background:none;border:none;cursor:pointer;line-height:inherit;">
              <i class="material-icons">person</i>
              <span class="d-sm-inline d-none">Sign Out</span>
            </button>
          </form>
        <li class="nav-item d-xl-none ps-3 d-flex align-items-center">
          <a href="#" class="nav-link p-0 text-body" id="iconNavbarSidenav">
            <div class="sidenav-toggler-inner">
              <i class="sidenav-toggler-line"></i>
              <i class="sidenav-toggler-line"></i>
              <i class="sidenav-toggler-line"></i>
            </div>
          </a>
        </li>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle account-logo" href="#" id="navbarDropdownMenuLink" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
            <i class="material-icons">settings</i>
          </a>
          <div class="dropdown-menu" aria-labelledby="navbarDropdownMenuLink">
            <a class="dropdown-item" href="upsertEventII.php">Manage Events</a>
            <a class="dropdown-item" href="#">Initalize Event</a>
            <a class="dropdown-item" onclick="clickHandler('#myFormID', 1); return false;">Refresh Accommodation Counts</a>
            <a class="dropdown-item" onclick="clickHandler('#myFormID', 2); return false;">Refresh Seva Counts</a>
          </div>
        </li>
      </ul>
    </div>
  </div>
</nav>
