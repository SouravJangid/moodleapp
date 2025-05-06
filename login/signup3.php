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

require('../config.php');
require_once($CFG->dirroot . '/user/editlib.php');
require_once($CFG->libdir . '/authlib.php');
require_once('lib.php');

// Set up the page early
$PAGE->set_url('/login/signup3.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('login');
$PAGE->set_title(get_string('newaccount'));
$PAGE->set_heading($SITE->fullname);
$PAGE->requires->css('/theme/boost/scss/signuppostgraduation.scss');

// Prevent any output until we're ready
ob_start();

// Check if signup is enabled
if (!$authplugin = signup_is_enabled()) {
    ob_end_clean();
    throw new \moodle_exception('notlocalisederrormessage', 'error', '', 'Sorry, you may not use this page.');
}

// Handle wantsurl
if (empty($SESSION->wantsurl)) {
    $SESSION->wantsurl = $CFG->wwwroot . '/';
} else {
    $wantsurl = new moodle_url($SESSION->wantsurl);
    if ($PAGE->url->compare($wantsurl, URL_MATCH_BASE)) {
        $SESSION->wantsurl = $CFG->wwwroot . '/';
    }
}

// Handle logged-in users
if (isloggedin() && !isguestuser()) {
    ob_end_clean();
    echo $OUTPUT->header();
    echo $OUTPUT->box_start();
    $logout = new single_button(new moodle_url('/login/logout.php', ['sesskey' => sesskey(), 'loginpage' => 1]), get_string('logout'), 'post');
    $continue = new single_button(new moodle_url('/'), get_string('cancel'), 'get');
    echo $OUTPUT->confirm(get_string('cannotsignup', 'error', fullname($USER)), $logout, $continue);
    echo $OUTPUT->box_end();
    echo $OUTPUT->footer();
    exit;
}

// Handle digital consent verification
if (\core_auth\digital_consent::is_age_digital_consent_verification_enabled()) {
    $cache = cache::make('core', 'presignup');
    $isminor = $cache->get('isminor');
    if ($isminor === false) {
        ob_end_clean();
        redirect(new moodle_url('/login/verify_age_location.php'));
    } else if ($isminor === 'yes') {
        ob_end_clean();
        redirect(new moodle_url('/login/digital_minor.php'));
    }
}

// Pre-signup requests and form initialization
core_login_pre_signup_requests();
$mform_signup = $authplugin->signup_form();

// Handle form submission
if ($mform_signup->is_cancelled()) {
    ob_end_clean();
    redirect(get_login_url());
} else if ($user = $mform_signup->get_data()) {
    ob_end_clean();
    $user = signup_setup_new_user($user);
    core_login_post_signup_requests($user);
    $authplugin->user_signup($user, true);
    exit;
}

// Set navigation (before rendering)
$newaccount = get_string('newaccount');
$login = get_string('login');
$PAGE->navbar->add($login);
$PAGE->navbar->add($newaccount);

// Now start rendering
ob_end_clean();
echo $OUTPUT->header();

// Custom login view
?>
<div class="login-view"> 
    <div class="images">
        <img src="<?php echo $CFG->wwwroot; ?>/theme/boost/pix/images/stp1.png" alt="" class="login-image">
    </div>
    <div class="login-imgtitle">
        <h2 class="login-title">Transforming Education with AI Innovation</h2>
    </div>
    <div class="tilted-rectangles">
        <div></div>
        <div></div>
        <div></div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const messages = [
            "Transforming Education with AI Innovation",
            "Fortified Data Security",
            "Empowering Learners Globally"
        ];

        const images = [
            "<?php echo $CFG->wwwroot; ?>/theme/boost/pix/images/stp1.png",
            "<?php echo $CFG->wwwroot; ?>/theme/boost/pix/images/stp2.png",
            "<?php echo $CFG->wwwroot; ?>/theme/boost/pix/images/stp3.png"
        ];

        let index = 0;
        const messageElement = document.querySelector('.login-title');
        const imageElement = document.querySelector('.login-image');

        if (messageElement && imageElement) {
            setInterval(() => {
                messageElement.textContent = messages[index];
                imageElement.src = images[index];
                index = (index + 1) % messages.length;
            }, 7000);
        }
    });
</script>

<?php
// Render the signup form
if ($mform_signup instanceof renderable) {
    try {
        $renderer = $PAGE->get_renderer('auth_' . $authplugin->authtype);
        echo $renderer->render($mform_signup);
    } catch (coding_exception $ce) {
        echo $OUTPUT->render($mform_signup);
    }
} else {
    $mform_signup->display();
}

// Finish rendering
echo $OUTPUT->footer();
?>