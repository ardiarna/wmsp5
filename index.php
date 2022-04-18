<?php
require_once __DIR__ . '/vendor/autoload.php';
use Security\RoleAcl;
use Utils\Env;

SessionUtils::sessionStart();

if (!SessionUtils::isAuthenticated()) {
  $loginUrl = HttpUtils::fullUrl($_SERVER) . 'login.php';
  header("Location: $loginUrl");
  exit;
}

$user = SessionUtils::getUser();

?>
<!DOCTYPE HTML>
<html lang="id">
<head>
  <meta charset="utf-8"/>
  <style>
    html, body {
      width: 100%;
      height: 100%;
      overflow: hidden;
      margin: 0px;
      background-color: #EBEBEB;
    }
  </style>
  <link rel="stylesheet" type="text/css" href="assets/libs/dhtmlx/dhtmlx.css"/>
  <link rel="stylesheet" type="text/css" href="assets/fonts/font_awesome/css/font-awesome.min.css"/>
  <link rel="stylesheet" type="text/css" href="assets/fonts/font_roboto/roboto.css"/>

  <title>Arwana WMS - Plant <?= PlantIdHelper::getCurrentPlant() ?></title>

  <script src="assets/libs/axios/axios.min.js"></script>
  <script src="assets/js/date-utils.js"></script>
  <script src="assets/js/WMSApi-20190723-02.js"></script>
  <script src="assets/js/error-handler-20190723-02.js"></script>

  <script src="assets/libs/dhtmlx/dhtmlx.js"></script>
  <script>
    "use strict";

    const main = (function (dhtmlx, WMSApi, handleApiError) {
      function enforceRoleAcl() {
        const menu = rootLayout.getAttachedMenu();

        <?php /* ACL-based menu control. */ ?>
        <?php if (UserRole::hasAnyRole(array(UserRole::ROLE_MARKETING_STAFF, UserRole::ROLE_MARKETING_MANAGER))): ?>
        menu.setItemDisabled('master_logistics_area');
        menu.hideItem('stock_summary_for_sales');

        // add access to logistics dashboard, for location checking
        menu.addNewChild('stock', 0, 'stock_location', 'Ringkasan Stok dengan Lokasi', false, null, null);
        menu.setUserData('stock_location', 'href', 'LogisticsDashboard.html');
        <?php endif ?>
        <?php if (!UserRole::hasAnyRole(RoleAcl::mutationReport())): ?>
        menu.setItemDisabled('stock_mutation_summary_by_motif');
        <?php endif; ?>
        <?php if (!UserRole::hasAnyRole(RoleAcl::downgradePallets())): ?>
        menu.setItemDisabled('qa_downgrade');
        <?php endif; ?>
        <?php if (!UserRole::hasAnyRole(RoleAcl::downgradePalletsReasonModification())): ?>
        menu.setItemDisabled('master_qa_downgrade_reasons');
        <?php endif; ?>
        <?php if (!UserRole::hasAnyRole(RoleAcl::masterArea())): ?>
        menu.setItemDisabled('master_logistics_area');
        <?php endif; ?>
        <?php /* End of ACL-based menu control. */ ?>

        // setup dashboard
        const tabbar = rootLayout.cells('a').getAttachedObject();
        <?php if (UserRole::hasAnyRole(array(UserRole::ROLE_MARKETING_MANAGER, UserRole::ROLE_MARKETING_STAFF))): ?>
        tabbar.tabs('dashboard').attachURL(menu.getUserData('stock_summary_for_sales', 'href'));
        <?php else: ?>
        tabbar.tabs('dashboard').attachURL('LogisticsDashboard.html');
        <?php endif ?>

        // superuser: for debugging, allow him/her to change role as required.
        <?php if (UserRole::isSuperuser()): ?>
        const toolbar = rootLayout.getAttachedToolbar();
        toolbar.addListOption('user', null, 0, 'separator', null, null);
        toolbar.addListOption('user', 'user_change_role', 0, 'button', 'Ubah Role', null);
        <?php endif ?>
      }

      let rootLayout, windows;
      function doOnLoad() {
        rootLayout = new dhtmlXLayoutObject({
          parent: document.body,
          pattern: '1C',
          cells: [{id: "a", text: "", header: false}]
        });
        windows = new dhtmlXWindows();

        const rootToolbar = rootLayout.attachToolbar({
          items: [
            {type: 'text', id: 'plant_name', text: '<?= Env::get('PLANT_NAME') ?>'},
            {type: 'spacer'},
            {
              type: 'buttonSelect', id: 'user', text: '<?= $user->gua_nama ?>', options: [
                {type: 'button', id: 'change-password', text: 'Ubah Password'},
                {type: 'button', id: 'logout', text: 'Log Out'}
              ]
            }
          ]
        });
        rootToolbar.attachEvent('onClick', (id) => {
          switch (id) {
            case 'change-password':
              changePassword();
              break;
            case 'logout':
              logout();
              break;
            <?php if (UserRole::isSuperuser()): ?>
            case 'user_change_role':
              changeUserRole();
              break;
            <?php endif ?>
          }
        });
        const rootTabbar = rootLayout.cells("a").attachTabbar({
          tabs: [
            {id: 'dashboard', text: 'Dashboard', active: true}
          ]
        });
        const rootMenu = rootLayout.attachMenu({
          iconset: 'awesome',
          xml: 'common/menu.xml',
          onload: enforceRoleAcl
        });
        rootMenu.attachEvent('onClick', id => {
          // check if tab exists
          if (rootTabbar.tabs(id) === null) {
            // open new, closeable tab.
            const tabTitle = rootMenu.getItemText(id);
            const href = rootMenu.getUserData(id, 'href');

            rootTabbar.addTab(id, tabTitle, null, null, true, true);
            rootTabbar.tabs(id).attachURL(href);
          } else {
            rootTabbar.tabs(id).setActive();
          }
        });
      }

      function logout() {
        dhtmlx.confirm({
          title: 'Konfirmasi Log Out',
          type: 'confirm-warning',
          text: 'Apakah Anda yakin mau keluar?',
          callback: (confirmed) => {
            if (confirmed) {
              window.location.href = 'logout.php';
            }
          }
        })
      }

      function changePassword() {
        const w1 = windows.createWindow("pass_change", 0, 0, 450, 250);
        w1.centerOnScreen();
        w1.setText("Edit Password");
        w1.button("park").hide();
        w1.button("minmax").hide();
        w1.setModal(true);

        const formLabels = {
          'current_password': 'Password Saat Ini',
          'new_password': 'Password Baru',
          'new_password_confirm': 'Password Baru (Konfirm)'
        };
        const formStructure = [

          {type: "settings", position: "label-left", labelAlign: "left", labelWidth: 130, inputWidth: 230,},
          {
            type: "block", width: 400, offsetLeft: 0, offsetTop: 0, list: [
              {
                type: "fieldset", name: "mydata1", label: "Edit Password", width: 400, list: [
                  {
                    type: 'password',
                    name: 'current_password',
                    label: formLabels['current_password'],
                    inputWidth: 150,
                    required: true
                  },
                  {
                    type: 'password',
                    name: 'new_password',
                    label: formLabels['new_password'],
                    inputWidth: 150,
                    required: true
                  },
                  {
                    type: 'password',
                    name: 'new_password_confirm',
                    label: formLabels['new_password_confirm'],
                    inputWidth: 150,
                    required: true
                  },
                  {
                    type: "block", offsetTop: 0, offsetLeft: 110, list: [
                      {type: "button", name: "save", value: "Simpan"},
                      {type: "newcolumn"},
                      {type: "button", name: "close", value: "Batal"}
                    ]
                  }
                ]
              }
            ]
          }
        ];

        const changePasswordForm = w1.attachForm(formStructure);

        changePasswordForm.setFontSize("11px");
        changePasswordForm.attachEvent("onButtonClick", function (id) {
          if (id === "save") {
            if (!changePasswordForm.validate()) {
              dhtmlx.alert({
                title: 'Ubah Password',
                type: 'alert-warning',
                text: 'Input not Complete'
              });
            } else {
              const currentPassword = changePasswordForm.getItemValue('current_password');
              const newPassword = changePasswordForm.getItemValue('new_password');
              const newPasswordConfirm = changePasswordForm.getItemValue('new_password_confirm');
              w1.progressOn();
              WMSApi.auth.changeSelfPassword(currentPassword, newPassword, newPasswordConfirm)
                .then(confirmMessage => {
                  w1.progressOff();
                  dhtmlx.alert({
                    title: 'Ubah Password',
                    type: '',
                    text: confirmMessage,
                    callback: () => {
                      w1.close();
                    }
                  })
                })
                .catch(error => {
                  w1.progressOff();
                  let errorMessage;
                  if (error.errorType === WMSApi.ApiError.TYPES.AXIOS_RESPONSE) {
                    // check the HTTP status code
                    const response = error.origin.response;
                    const statusCode = response.status;
                    if (statusCode === 403 || statusCode === 400) {
                      errorMessage = error.message;
                      if (response.data instanceof Object && response.data.data instanceof Object) {
                        errorMessage += `<br/><ul>`;
                        Object.keys(response.data.data).forEach(key => {
                          errorMessage += `<li>${formLabels[key]}:&nbsp;${response.data.data[key]}</li>`;
                        });
                        errorMessage += `</ul>`;
                      }
                    }
                  } else {
                    errorMessage = error.message
                  }

                  dhtmlx.alert({
                    title: 'Ubah Password',
                    type: 'alert-warning',
                    text: errorMessage
                  })
                })
            }
          } else if (id === "close") {
            w1.close();
          }
        })
      }

      <?php if (UserRole::isSuperuser()): ?>
      <?php
      $availableRoles = array();
      /** @noinspection PhpUnhandledExceptionInspection */
      $reflection = new ReflectionClass(UserRole::getFullClassName());
      $consts = $reflection->getConstants();
      $len_role = strlen('ROLE_');
      foreach ($consts as $key => $val) {
        if (substr($key, 0, 4) === 'ROLE') {
          $availableRoles[$val] = substr($key, $len_role);
        }
      }
      ?>
      // for superuser only
      // noinspection JSAnnotator
      const ROLES = <?= json_encode($availableRoles) ?>;
      function changeUserRole() {
        const w1 = windows.createWindow("role_change", 0, 0, 450, 130);
        w1.centerOnScreen();
        w1.setText("Edit User Role");
        w1.button("park").hide();
        w1.button("minmax").hide();
        w1.setModal(true);
        const formStructure = [
          {type: "settings", position: "label-left", labelAlign: "left", labelWidth: 130, inputWidth: 300},
          {
            type: "block", width: 400, offsetLeft: 0, offsetTop: 0, list: [
              {
                type: 'combo',
                name: 'role',
                label: 'User Role',
                inputWidth: 220,
                required: true,
                options: Object.keys(ROLES).map(role => ({
                  text: ROLES[role],
                  value: role,
                  selected: '<?= $user->roles[0] ?>' === role
                }))
              }
            ]
          },
          {
            type: "block", offsetTop: 0, offsetLeft: 110, list: [
              {type: "button", name: "save", value: "Simpan"},
              {type: "newcolumn"},
              {type: "button", name: "close", value: "Batal"}
            ]
          }
        ];
        const form = w1.attachForm(formStructure);
        form.attachEvent('onButtonClick', buttonId => {
          if (buttonId === 'close') {
            w1.close();
            return;
          }

          if (buttonId === 'save') {
            const newRole = form.getItemValue('role');
            w1.progressOn();
            WMSApi.auth.changeUserRole(newRole)
              .then(() => {
                w1.progressOff();
                location.reload();
              })
              .catch(error => {
                w1.progressOff();
                handleApiError(error);
              })
          }
        })
      }
      <?php endif; ?>

      return {doOnLoad};
    })(dhtmlx, WMSApi, handleApiError);
  </script>
</head>
<body onload="main.doOnLoad();">
</body>
</html>
