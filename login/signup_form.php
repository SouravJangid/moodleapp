<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * User sign-up form with Twilio Verify OTP integration.
 *
 * @package    core
 * @subpackage auth
 * @copyright  1999 onwards Martin Dougiamas  http://dougiamas.com
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir.'/formslib.php');
require_once($CFG->dirroot.'/user/profile/lib.php');
require_once($CFG->dirroot . '/user/editlib.php');
require_once($CFG->dirroot . '/login/lib.php');
require_once('lib.php');


class login_signup_form extends moodleform implements renderable, templatable {
    function definition() {
        global $USER, $CFG;

        $mform = $this->_form;

        // Email field
        $mform->addElement('text', 'email', get_string('email'), 'maxlength="100" size="25"');
        $mform->setType('email', core_user::get_property_type('email'));
        $mform->addRule('email', get_string('missingemail'), 'required', null, 'client');
        $mform->setForceLtr('email');

        // Password field
        $mform->addElement('password', 'password', get_string('password'), [
            'maxlength' => MAX_PASSWORD_CHARACTERS,
            'size' => 12,
            'autocomplete' => 'new-password'
        ]);
        $mform->setType('password', core_user::get_property_type('password'));
        $mform->addRule('password', get_string('missingpassword'), 'required', null, 'client');
        $mform->addRule('password', get_string('maximumchars', '', MAX_PASSWORD_CHARACTERS),
            'maxlength', MAX_PASSWORD_CHARACTERS, 'client');

        // First and Last name fields
        $namefields = useredit_get_required_name_fields();
        foreach ($namefields as $field) {
            $mform->addElement('text', $field, get_string($field), 'maxlength="100" size="30"');
            $mform->setType($field, core_user::get_property_type('firstname'));
            $stringid = 'missing' . $field;
            if (!get_string_manager()->string_exists($stringid, 'moodle')) {
                $stringid = 'required';
            }
            $mform->addRule($field, get_string($stringid), 'required', null, 'client');
        }

        // Country code field
        $asiancountrycodes = [
            '+91' => '+91 (India)',
            '+93' => '+93 (Afghanistan)',
            '+94' => '+94 (Sri Lanka)',
            '+95' => '+95 (Myanmar)',
            '+66' => '+66 (Thailand)',
            '+60' => '+60 (Malaysia)',
            '+62' => '+62 (Indonesia)',
            '+63' => '+63 (Philippines)',
            '+65' => '+65 (Singapore)',
            '+81' => '+81 (Japan)',
            '+82' => '+82 (South Korea)',
            '+86' => '+86 (China)',
            '+84' => '+84 (Vietnam)',
            '+880' => '+880 (Bangladesh)',
            '+92' => '+92 (Pakistan)',
        ];
        
        $mform->addElement('select', 'countrycode', get_string('countrycode'), $asiancountrycodes);
        $mform->setType('countrycode', PARAM_TEXT);
        $mform->setDefault('countrycode', '+91');
        $mform->addRule('countrycode', get_string('missingcountrycode', 'moodle'), 'required', null, 'client');
        $mform->addHelpButton('countrycode', 'countrycode');

        // Mobile number field
        $mform->addElement('text', 'phone', get_string('phone'), 
            ['maxlength' => '15', 'size' => '15', 'placeholder' => 'Enter mobile number']);
        $mform->setType('phone', PARAM_TEXT);
        $mform->addRule('phone', get_string('missingphone', 'moodle'), 'required', null, 'client');
        $mform->addRule('phone', get_string('invalidphoneformat', 'moodle'), 
            'regex', '/^\d{10,15}$/', 'client');
        $mform->addHelpButton('phone', 'phone');

        // Send OTP button
        $mform->addElement('button', 'sendotp', 'Send OTP', ['id' => 'sendotp']);

        // OTP field (hidden initially)
        $mform->addElement('text', 'otp', get_string('otp'), 
            ['maxlength' => '6', 'size' => '10', 'placeholder' => 'Enter OTP']);
        $mform->setType('otp', PARAM_TEXT);
        $mform->addRule('otp', get_string('missingotp', 'moodle'), 'required', null, 'client');
        $mform->addRule('otp', get_string('invalidotpformat', 'moodle'), 'regex', '/^\d{6}$/', 'client');

        // Verify OTP and Resend OTP buttons in a group
        $buttonarray = array();
        $buttonarray[] = $mform->createElement('button', 'verifyotp', 'Verify OTP', ['id' => 'verifyotp']);
        $buttonarray[] = $mform->createElement('button', 'resendotp', 'Resend OTP', ['id' => 'resendotp']);
        $mform->addGroup($buttonarray, 'otpbuttons', '', array(' '), false);

         // Notification area below phone input
         $mform->addElement('html', '<div id="phone-notification" class="notification-area"></div>');


        // Hidden fields for username and email2
        $mform->addElement('hidden', 'username');
        $mform->setType('username', PARAM_RAW);
        $mform->addElement('hidden', 'email2');
        $mform->setType('email2', core_user::get_property_type('email'));

        // JavaScript for auto-fill, OTP sending, and optional client-side verification
       // JavaScript with auto-hide functionality
       $mform->addElement('html', '
       <script>
           document.addEventListener("DOMContentLoaded", function() {
               var emailInput = document.querySelector(\'input[name="email"]\');
               var usernameInput = document.querySelector(\'input[name="username"]\');
               var email2Input = document.querySelector(\'input[name="email2"]\');
               var sendOtpButton = document.querySelector("#sendotp");
               var verifyOtpButton = document.querySelector("#verifyotp");
               var resendOtpButton = document.querySelector("#resendotp");
               var otpField = document.querySelector("#fitem_id_otp");
               var phoneInput = document.querySelector(\'input[name="phone"]\');
               var countryCodeSelect = document.querySelector(\'select[name="countrycode"]\');
               var form = document.querySelector("form");
               var otpInput = document.querySelector(\'input[name="otp"]\');
               var notificationArea = document.querySelector("#phone-notification");

               // Function to show notification and hide after 7 seconds
               function showNotification(message, className) {
                   notificationArea.innerHTML = `<span class="${className}">${message}</span>`;
                   setTimeout(() => {
                       notificationArea.innerHTML = "";
                   }, 7000);
               }

               // Email autofill
               if (emailInput && usernameInput && email2Input) {
                   emailInput.addEventListener("input", function() {
                       usernameInput.value = emailInput.value;
                       email2Input.value = emailInput.value;
                   });
               }

               // Button controls
               if (sendOtpButton && verifyOtpButton && resendOtpButton && otpField && phoneInput && countryCodeSelect) {
                   sendOtpButton.style.display = "none";
                   verifyOtpButton.style.display = "none";
                   resendOtpButton.style.display = "none";
                   otpField.style.display = "none";

                   // Phone number validation
                   phoneInput.addEventListener("input", function() {
                       var phone = phoneInput.value;
                       notificationArea.innerHTML = "";
                       if (/^\d{10,15}$/.test(phone)) {
                           sendOtpButton.style.display = "inline-block";
                       } else {
                           sendOtpButton.style.display = "none";
                           verifyOtpButton.style.display = "none";
                           resendOtpButton.style.display = "none";
                           otpField.style.display = "none";
                       }
                   });

                   // Send OTP
                   sendOtpButton.addEventListener("click", function(e) {
                       e.preventDefault();
                       var phone = phoneInput.value;
                       var countryCode = countryCodeSelect.value;
                       var fullPhone = countryCode + phone;

                       if (/^\d{10,15}$/.test(phone)) {
                           showNotification("Sending OTP...", "form-info");
                           fetch("' . $CFG->wwwroot . '/auth/otp_send.php", {
                               method: "POST",
                               headers: { "Content-Type": "application/x-www-form-urlencoded" },
                               body: "phone=" + encodeURIComponent(fullPhone)
                           })
                           .then(response => response.json())
                           .then(data => {
                               if (data.success) {
                                   showNotification("OTP sent successfully! Check your phone.", "form-success");
                                   sendOtpButton.style.display = "none";
                                   verifyOtpButton.style.display = "inline-block";
                                   resendOtpButton.style.display = "inline-block";
                                   otpField.style.display = "block";
                               } else {
                                   showNotification("Failed to send OTP: " + data.error, "form-error");
                               }
                           })
                           .catch(error => {
                               showNotification("Error: " + error, "form-error");
                           });
                       }
                   });

                   // Verify OTP
                   verifyOtpButton.addEventListener("click", function(e) {
                       e.preventDefault();
                       var otp = otpInput.value;
                       var phone = phoneInput.value;
                       var countryCode = countryCodeSelect.value;
                       var fullPhone = countryCode + phone;

                       if (/^\d{6}$/.test(otp)) {
                           showNotification("Verifying OTP...", "form-info");
                           fetch("' . $CFG->wwwroot . '/auth/otp_verify.php", {
                               method: "POST",
                               headers: { "Content-Type": "application/x-www-form-urlencoded" },
                               body: "phone=" + encodeURIComponent(fullPhone) + "&otp=" + encodeURIComponent(otp)
                           })
                           .then(response => response.json())
                           .then(data => {
                               if (data.success) {
                                   showNotification("OTP verified successfully!", "form-success");
                                   verifyOtpButton.style.display = "none";
                                   resendOtpButton.style.display = "none";
                                   otpInput.disabled = true;
                               } else {
                                   showNotification("OTP verification failed: " + data.message, "form-error");
                               }
                           })
                           .catch(error => {
                               showNotification("Error: " + error, "form-error");
                           });
                       } else {
                           showNotification("Please enter a valid 6-digit OTP", "form-error");
                       }
                   });

                   // Resend OTP
                   resendOtpButton.addEventListener("click", function(e) {
                       e.preventDefault();
                       var phone = phoneInput.value;
                       var countryCode = countryCodeSelect.value;
                       var fullPhone = countryCode + phone;

                       showNotification("Resending OTP...", "form-info");
                       fetch("' . $CFG->wwwroot . '/auth/otp_send.php", {
                           method: "POST",
                           headers: { "Content-Type": "application/x-www-form-urlencoded" },
                           body: "phone=" + encodeURIComponent(fullPhone)
                       })
                       .then(response => response.json())
                       .then(data => {
                           if (data.success) {
                               showNotification("OTP resent successfully! Check your phone.", "form-success");
                           } else {
                               showNotification("Failed to resend OTP: " + data.error, "form-error");
                           }
                       })
                       .catch(error => {
                           showNotification("Error: " + error, "form-error");
                       });
                   });
               }

               // Form submit validation feedback
               form.addEventListener("submit", function(e) {
                   var errors = document.querySelectorAll(".form-error");
                   errors.forEach(function(error) {
                       error.style.display = "none";
                   });
               });
           });
       </script>
       ');

       // Updated CSS without height for notification area
       $mform->addElement('html', '
       <style>
           #fitem_id_countrycode .felement.fselect select { width: 100% !important; }
           .signupform { padding: 2% 9%; flex:1; }
           .fgroup.phonegroup { display: flex; align-items: center; gap: 10px; width: 100%; }
           .notification-area { margin-top: 5px; height: auto; margin-bottom: 0px}
           .form-error { 
               display: block; 
               color: #a94442; 
               font-size: 0.9em; 
               border: 1px solid #ff3333; 
               padding: 5px; 
           }
           .form-success { 
               display: block; 
               color: #28a745; 
               font-size: 0.9em; 
               border: 1px solid #28a745; 
               padding: 5px; 
           }
           .form-info {
               display: block;
               color: #666;
               font-size: 0.9em;
               border: 1px solid #ccc;
               padding: 5px;
           }
           .required-field:invalid { border: 2px solid #ff3333 !important; }
           .required-field:valid { border: 1px solid #ccc !important; }
           #sendotp, #verifyotp, #resendotp { 
               margin-left: 10px; 
               display: none; 
           }
           #fitem_id_otp { 
               display: none; 
               margin-top: 10px; 
           }
               #id_countrycode{
               width:100%;}
              .d-flex.flex-wrap.align-items-center {
        flex-direction: row-reverse;
        justify-content: space-between;
    }
       </style>
       ');



        profile_signup_fields($mform);

        if (signup_captcha_enabled()) {
            $mform->addElement('recaptcha', 'recaptcha_element', get_string('security_question', 'auth'));
            $mform->addHelpButton('recaptcha_element', 'recaptcha', 'auth');
            $mform->closeHeaderBefore('recaptcha_element');
        }

        core_login_extend_signup_form($mform);

        $manager = new \core_privacy\local\sitepolicy\manager();
        $manager->signup_form($mform);

        $this->add_action_buttons(true, get_string('createaccount'));
        $this->set_display_vertical();
    }

    function definition_after_data() {
        $mform = $this->_form;
        $mform->applyFilter('username', 'trim');

        foreach (useredit_get_required_name_fields() as $field) {
            $mform->applyFilter($field, 'trim');
        }
    }

    public function validation($data, $files) {
        global $CFG, $SESSION;

        $errors = parent::validation($data, $files);
        $errors = array_merge($errors, core_login_validate_extend_signup_form($data));

        // CAPTCHA validation
        if (signup_captcha_enabled()) {
            $recaptchaelement = $this->_form->getElement('recaptcha_element');
            if (!empty($this->_form->_submitValues['g-recaptcha-response'])) {
                $response = $this->_form->_submitValues['g-recaptcha-response'];
                if (!$recaptchaelement->verify($response)) {
                    $errors['recaptcha_element'] = get_string('incorrectpleasetryagain', 'auth');
                }
            } else {
                $errors['recaptcha_element'] = get_string('missingrecaptchachallengefield');
            }
        }

        // Phone number validation
        if (!empty($data['phone'])) {
            $phone = $data['phone'];
            if (!preg_match('/^\d{10,15}$/', $phone)) {
                $errors['phone'] = get_string('invalidphoneformat', 'moodle');
            }
        } else {
            $errors['phone'] = get_string('missingphone', 'moodle');
        }

    

         // OTP verification check
         if (empty($SESSION->otp_verified)) {
            $errors['otp'] = 'Please verify your OTP before submitting';
        }

        $errors += signup_validate_data($data, $files);

        return $errors;
    }

    public function export_for_template(renderer_base $output) {
        ob_start();
        $this->display();
        $formhtml = ob_get_contents();
        ob_end_clean();
        $context = [
            'formhtml' => $formhtml
        ];
        return $context;
    }
}