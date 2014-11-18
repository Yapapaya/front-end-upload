<?php
/*
 Plugin Name: Front End Upload
 Description: Allow your visitors to upload files. Insert the upload form with the shortcode <code>[front-end-upload]</code>.
 Version: 0.6.1
*/

/*  Copyright 2012

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License as published by
 the Free Software Foundation; either version 2 of the License, or
 (at your option) any later version.

 This program is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details.

 You should have received a copy of the GNU General Public License
 along with this program; if not, write to the Free Software
 Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
 */

global $blog_id;
global $feu_destination_dir;
global $feu_destination_url;
global $front_end_upload;

// constant definition
if( !defined( 'IS_ADMIN' ) )
    define( 'IS_ADMIN', is_admin() );

define( 'FEU_VERSION',  '0.6.1' );
define( 'FEU_PREFIX',   '_iti_feu_' );
define( 'FEU_DIR',      WP_PLUGIN_DIR . '/' . basename( dirname( __FILE__ ) ) );
define( 'FEU_URL',      rtrim( plugin_dir_url( __FILE__ ), '/' ) );

// randomize the destination for security
if( !$salt = get_option( '_feufilesalt' ) )
    $salt = update_option( '_feufilesalt', 'feu_' . md5( uniqid( 'feu_uploads_' ) ) );

if ( !is_multisite() )
{
    $feu_destination_dir = WP_CONTENT_DIR . '/uploads/' . $salt;
    $feu_destination_url = WP_CONTENT_URL . '/uploads/' . $salt;
}
else
{
    $feu_destination_dir = WP_CONTENT_DIR . '/blogs.dir/' . $blog_id .'/files/' . $salt;
    $feu_destination_url = WP_CONTENT_URL . '/blogs.dir/' . $blog_id .'/files/' . $salt;
}

define( 'FEU_DESTINATION_DIR', $feu_destination_dir );
define( 'FEU_DESTINATION_URL', $feu_destination_url );

// cleanup potential unwanted dir (added version 0.5.4)
$unwanted = dirname( __FILE__ ) . DIRECTORY_SEPARATOR . 'FEU_DESTINATION_DIR';
if( file_exists( $unwanted ) )
{
    rename( $unwanted, uniqid( $unwanted ) );
}

// WordPress actions
if( IS_ADMIN )
{
    add_action( 'admin_init',           array( 'FrontEndUpload', 'environment_check' ) );
    add_action( 'admin_init',           array( 'FrontEndUpload', 'register_settings' ) );
    add_action( 'admin_menu',           array( 'FrontEndUpload', 'assets' ) );
}
else
{
    // we depend on jQuery and Plupload on the front end
    add_action( 'init',                 array( 'FrontEndUpload', 'assets_public' ) );
    add_action( 'get_footer',           array( 'FrontEndUpload', 'init_plupload' ) );
}

// we also need to do some maintenance
add_action( 'wp_scheduled_delete',      array( 'FrontEndUpload', 'cleanup_transients' ) );
add_action( 'init',                     array( 'FrontEndUpload', 'register_storage' ) );
add_action( 'parse_request',            array( 'FrontEndUpload', 'process_download' ) );


/**
 * Front End Upload
 *
 * @package WordPress
 **/
class FrontEndUpload
{
    public $settings    = array(
            'version'   => FEU_VERSION,
            );


    /**
     * Constructor
     * Sets default options, initializes localization and shortcodes
     *
     * @return void
     */
    function __construct()
    {
        $settings = get_option( FEU_PREFIX . 'settings' );
        if( !$settings )
        {
            // first run
            self::first_run();
            add_option( FEU_PREFIX . 'settings', $this->settings, '', 'yes' );
        }
        else
        {
            $this->settings = $settings;
        }

        // localization
        self::l10n();

        // shortcode init
        if( !IS_ADMIN )
        {
            self::init_shortcodes();
        }
    }


    function hash_location( $filename, $hash )
    {
        $salt = get_option( '_feufilesalt' );

        if( empty( $salt ) || empty( $filename ) || empty( $hash ) )
            die();

        $recordid = md5( $filename . $hash . $salt );
        update_option( $recordid, $filename );

        return $recordid;
    }



    function mkdir_recursive( $path )
    {
        if ( empty( $path ) )
            return;

        is_dir( dirname( $path ) ) || self::mkdir_recursive( dirname( $path ) );

        if ( is_dir( $path ) === TRUE ) {
            return TRUE;
        } else {
            return @mkdir( $path );
        }
    }


    /**
     * Checks to ensure we have proper WordPress and PHP versions
     *
     * @return void
     */
    function environment_check()
    {
        $wp_version = get_bloginfo( 'version' );
        if( !version_compare( PHP_VERSION, '5.2', '>=' ) || !version_compare( $wp_version, '3.2', '>=' ) )
        {
            if( IS_ADMIN && ( !defined( 'DOING_AJAX' ) || !DOING_AJAX ) )
            {
                require_once ABSPATH.'/wp-admin/includes/plugin.php';
                deactivate_plugins( __FILE__ );
                wp_die( __('Front End Upload requires WordPress 3.2 or higher, it has been automatically deactivated.') );
            }
            else
            {
                return;
            }
        }
        else
        {
            // PHP and WP versions check out, let's try to set up our upload destination
            if( !file_exists( FEU_DESTINATION_DIR ) )
            {
                if ( self::mkdir_recursive( FEU_DESTINATION_DIR ) === FALSE )
                {
                    wp_die( __('Error: Unable to create upload storage directory. Please verify write permissions to the designated WordPress uploads directory. Front End Upload has been deactivated.') );
                }
            }
        }
    }


    /**
     * Runs on first activation of plugin
     *
     * @return void
     */
    function first_run()
    {
        // null
    }


    /**
     * Load the translation of the plugin
     *
     * @return void
     */
    function l10n() {
        load_plugin_textdomain( 'frontendupload', FALSE, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
    }


    /**
     * Initialize appropriate shortcod
     *
     * @return void
     */
    function init_shortcodes() {
        add_shortcode( 'front-end-upload', array( 'FrontEndUpload', 'shortcode' ) );
    }


    /**
     * Shortcode handler that outputs the form, Plupload, and any feedback messages
     *
     * @return string $output Formatted HTML to be used in the theme
     */
    function shortcode( $atts ) {
        // grab FEU's settings
        $settings   = get_option( FEU_PREFIX . 'settings' );

        $output     =  '<div class="front-end-upload-parent">';

        $nonce = isset( $_POST['_wpnonce'] ) ? $_POST['_wpnonce'] : '';

        // do we need to show the form?
        if( ( isset( $_POST['_wpnonce'] ) && wp_verify_nonce( $nonce, 'feuaccess' ) ) )
        {
            // verified upload submission, let's send the email

            // grab our filenames
            $files      = isset( $_POST['feu_file_ids'] ) ? $_POST['feu_file_ids'] : array();

            $file_list  = '';
            if( is_array( $files ) )
                foreach( $files as $filehash )
                    $file_list .= get_bloginfo( 'url' ) . '?feu=1&feuid=' . $filehash . "\n";

            $email      = isset( $_POST['feu_email'] ) ? mysql_real_escape_string( $_POST['feu_email'] ) : '';

            // grab the submitted message
            $message    = isset( $_POST['feu_message'] ) ? mysql_real_escape_string( $_POST['feu_message'] ) : '';
            $message    = stripslashes( $message );

            // let's parse our email template
            $parsed = !empty( $settings['email_template'] ) ? $settings['email_template'] : "New files have been submitted by {@email}. The files submitted were:\n\n{@files}\n\nAdditionally, a message was provided:\n\n==========\n{@message}\n==========";

            // we'll grab our submitter IP
            $ip = $_SERVER['REMOTE_ADDR'];

            $parsed = str_replace( '{@files}',      $file_list,                             $parsed );
            $parsed = str_replace( '{@email}',      $email,                                 $parsed );
            $parsed = str_replace( '{@message}',    $message,                               $parsed );
            $parsed = str_replace( '{@time}',       date( 'F jS, Y' ) . date( 'g:ia' ),     $parsed );
            $parsed = str_replace( '{@ip}',         $ip,                                    $parsed );

            $recipients     = !empty( $settings['email_recipients'] ) ? $settings['email_recipients'] : get_option( 'admin_email' );
            $subject        = !empty( $settings['email_subject'] ) ? $settings['email_subject'] : '[' . get_bloginfo( 'name' ) . '] New files uploaded';

            $headers = 'From: ' . get_bloginfo( 'name' ) . ' <' . get_option( 'admin_email' ) . '>' . "\r\n";

            wp_mail( $recipients, $subject, $parsed, $headers );

            // lastly we'll output our success message
            $success_message = isset( $settings['success_message'] ) ? $settings['success_message'] : '<strong>Your files have been received.</strong>';
            $output .= '<div class="front-end-upload-success">';
            $output .= wpautop( $success_message );
            $output .= '</div>';
        }
        else
        {
            $passcode    = isset( $_POST['feu_passcode'] ) ? $_POST['feu_passcode'] : '';

            $output     .= '<form action="" method="post" class="front-end-upload-flags">';

            // we're going to check to see if a passcode has been set and no passcode has been submitted (yet)
            // OR that a passcode has been set and the submitted passcode passes validation
            if( ( isset( $settings['passcode'] ) && !empty( $settings['passcode'] ) )           // passcode was set
                && empty( $passcode )                                                           // passcode was not submitted
                || ( ( !empty( $passcode ) && ( $passcode != $settings['passcode'] ) )          // invalid passcode submitted
                    && ( isset( $_POST['_wpnonce'] ) && wp_verify_nonce( $nonce, 'feunonce' ) ) // but only if valid submission
                    )
            )
            {
                $output     .= '<input type="hidden" name="_wpnonce" id="_wpnonce" value="' . wp_create_nonce( 'feunonce' ) . '" />';

                $output     .= '<div class="front-end-upload-passcode">';
                if( !empty( $passcode ) )
                {
                    $output .= '<div class="front-end-upload-error passcode-error">';
                    $output .= __( 'Invalid passcode', 'frontendupload' );
                    $output .= '</div>';
                }
                $output     .= '<label for="feu_passcode">' . __( 'Passcode', 'frontendupload' ) . '</label>';
                $output     .= '<input type="text" name="feu_passcode" id="feu_passcode" value="' . $passcode . '" />';
                $output     .= '</div>';
            }
            else
            {
                // we can go ahead and show the form

                // Plupload container
                $output     .= '<div class="front-end-upload"><p>' . __( "There is a conflict with the active theme or one of the active plugins. Please view your console to determine the issue.", 'frontendupload' ) . '</p></div>';

                // Email
                $output     .= '<div class="front-end-upload-email">';
                $output     .= '<label for="feu_email">' . __( 'Your Email Address', 'frontendupload' ) . '</label>';
                $output     .= '<input type="text" name="feu_email" class="required email" id="feu_email" value="" />';
                $output     .= '</div>';

                // Message
                $output     .= '<div class="front-end-upload-message">';
                $output     .= '<label for="feu_message">' . __( 'Message', 'frontendupload' ) . '</label>';
                $output     .= '<textarea name="feu_message" id="feu_message"></textarea>';
                $output     .= '</div>';

                // we're going to flag the fact that we've got a valid submission
                $output     .= '<input type="hidden" name="_wpnonce" id="_wpnonce" value="' . wp_create_nonce( 'feuaccess' ) . '" />';
            }

            $output         .= '<div class="front-end-upload-submit"><button type="submit" />' . __( 'Submit', 'frontendupload' ) . '</button></div>';
            $output         .= '</form>';
        }

        $output .= '</div>';

        return $output;

    }


    /**
     * Settings API implementation for plugin settings
     *
     * @return void
     */
    function register_settings()
    {
        // flag our settings
        register_setting(
            FEU_PREFIX . 'settings',                                // group
            FEU_PREFIX . 'settings',                                // name of options
            array( 'FrontEndUpload', 'validate_settings' )          // validation callback
        );

        add_settings_section(
            FEU_PREFIX . 'options',                                 // section ID
            'Options',                                              // title
            array( 'FrontEndUpload', 'edit_options' ),              // display callback
            FEU_PREFIX . 'options'                                  // page name (do_settings_sections)
        );

        // submission passcode
        add_settings_field(
            FEU_PREFIX . 'passcode',                                // unique field ID
            'Submission Passcode',                                  // title
            array( 'FrontEndUpload', 'edit_passcode' ),             // input box display callback
            FEU_PREFIX . 'options',                                 // page name (as above)
            FEU_PREFIX . 'options'                                  // first arg to add_settings_section
        );

        // file size limit
        add_settings_field(
            FEU_PREFIX . 'max_file_size',                           // unique field ID
            'Max File Size',                                        // title
            array( 'FrontEndUpload', 'edit_max_file_size' ),        // input box display callback
            FEU_PREFIX . 'options',                                 // page name (as above)
            FEU_PREFIX . 'options'                                  // first arg to add_settings_section
        );

        // custom file extensions
        add_settings_field(
            FEU_PREFIX . 'custom_file_extensions',                  // unique field ID
            'Custom File Extension(s)',                             // title
            array( 'FrontEndUpload', 'edit_file_extensions' ),      // input box display callback
            FEU_PREFIX . 'options',                                 // page name (as above)
            FEU_PREFIX . 'options'                                  // first arg to add_settings_section
        );

        // email recipients
        add_settings_field(
            FEU_PREFIX . 'email_recipients',                        // unique field ID
            'Email Recipient(s)',                                   // title
            array( 'FrontEndUpload', 'edit_email_recipients' ),     // input box display callback
            FEU_PREFIX . 'options',                                 // page name (as above)
            FEU_PREFIX . 'options'                                  // first arg to add_settings_section
        );

        // email subject
        add_settings_field(
            FEU_PREFIX . 'email_subject',                           // unique field ID
            'Email Subject',                                        // title
            array( 'FrontEndUpload', 'edit_email_subject' ),        // input box display callback
            FEU_PREFIX . 'options',                                 // page name (as above)
            FEU_PREFIX . 'options'                                  // first arg to add_settings_section
        );

        // email template
        add_settings_field(
            FEU_PREFIX . 'email_template',                          // unique field ID
            'Email Template',                                       // title
            array( 'FrontEndUpload', 'edit_email_template' ),       // input box display callback
            FEU_PREFIX . 'options',                                 // page name (as above)
            FEU_PREFIX . 'options'                                  // first arg to add_settings_section
        );

        // success message
        add_settings_field(
            FEU_PREFIX . 'success_message',                         // unique field ID
            'Success Message',                                      // title
            array( 'FrontEndUpload', 'edit_success_message' ),      // input box display callback
            FEU_PREFIX . 'options',                                 // page name (as above)
            FEU_PREFIX . 'options'                                  // first arg to add_settings_section
        );

    }


    /**
     * HTML output before settings fields
     *
     * @return void
     */
    function edit_options()
    { ?>
        <p style="padding-left:10px;"><?php _e( "An email is sent each time a Front End Upload is submitted. You can customize the recipients, the email itself, and other options here.", "frontendupload" ); ?></p>
    <?php }


    /**
     * Validates options
     *
     * @param $input
     * @return array $input Array of all associated options
     */
    function validate_settings($input)
    {
        return $input;
    }


    /**
     * Outputs the HTML used for the passcode field
     *
     * @return void
     */
    function edit_passcode()
    {
        $settings = get_option( FEU_PREFIX . 'settings' );
        ?>
        <input type="text" class="regular-text" name="<?php echo FEU_PREFIX; ?>settings[passcode]" value="<?php echo !empty( $settings['passcode'] ) ? $settings['passcode'] : ''; ?>" /> <span class="description">Require this passcode to submit a Front End Upload. <strong>Leave empty to disable.</strong></span>
    <?php }


    /**
     * Outputs the HTML used for the max file size field
     *
     * @return void
     */
    function edit_max_file_size()
    {
        $settings = get_option( FEU_PREFIX . 'settings' );
        ?>
        <input type="text" class="small-text" name="<?php echo FEU_PREFIX; ?>settings[max_file_size]" value="<?php echo !empty( $settings['max_file_size'] ) ? intval( $settings['max_file_size'] ) : '10'; ?>" /> <span class="description">MB</span>
    <?php }


    /**
     * Outputs the HTML used for the custom file extensions field
     *
     * @return void
     */
    function edit_file_extensions()
    {
        $settings = get_option( FEU_PREFIX . 'settings' );
        ?>
        <input type="text" class="regular-text" name="<?php echo FEU_PREFIX; ?>settings[custom_file_extensions]" value="<?php echo !empty( $settings['custom_file_extensions'] ) ? $settings['custom_file_extensions'] : ''; ?>" /> <span class="description">Comma separated, no period (e.g. html,css)</span>
    <?php }


    /**
     * Outputs the HTML used for the email recipient(s) field
     *
     * @return void
     */
    function edit_email_recipients()
    {
        $settings = get_option( FEU_PREFIX . 'settings' );
        ?>
        <input type="text" class="regular-text" name="<?php echo FEU_PREFIX; ?>settings[email_recipients]" value="<?php echo !empty( $settings['email_recipients'] ) ? $settings['email_recipients'] : get_option( 'admin_email' ); ?>" /> <span class="description">Separate multiple email addresses with commas</span>
    <?php }


    /**
     * Outputs the HTML used for the email subject field
     *
     * @return void
     */
    function edit_email_subject()
    {
        $settings = get_option( FEU_PREFIX . 'settings' );
        ?>
        <input type="text" class="regular-text" name="<?php echo FEU_PREFIX; ?>settings[email_subject]" value="<?php echo !empty( $settings['email_subject'] ) ? $settings['email_subject'] : '[' . get_bloginfo( 'name' ) . '] New files uploaded'; ?>" />
    <?php }


    /**
     * Outputs the HTML used for the email template field
     *
     * @return void
     */
    function edit_email_template()
    {
        $settings = get_option( FEU_PREFIX . 'settings' );
        ?>
        <textarea rows="10" cols="50" class="large-text code" name="<?php echo FEU_PREFIX; ?>settings[email_template]"><?php echo !empty( $settings['email_template'] ) ? $settings['email_template'] : "New files have been submitted by {@email}. The files submitted were:\n\n{@files}\n\nAdditionally, a message was provided:\n\n==========\n{@message}\n=========="; ?></textarea>
        <div class="front-end-upload-tags">
            <a id="feu-tags-toggle" href="#feu-tags"><?php _e( "Tags Available" ); ?></a>
            <div id="feu-tags" >
                <table>
                    <thead>
                        <tr>
                            <th><?php _e( "Tag", "frontendupload" ); ?></th>
                            <th><?php _e( "Output", "frontendupload" ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><code>{@files}</code></td>
                            <td><?php _e( "List of files uploaded", "frontendupload" ); ?></td>
                        </tr>
                        <tr>
                            <td><code>{@email}</code></td>
                            <td><?php _e( "Email address of submitter", "frontendupload" ); ?></td>
                        </tr>
                        <tr>
                            <td><code>{@message}</code></td>
                            <td><?php _e( "Message from submitter", "frontendupload" ); ?></td>
                        </tr>
                        <tr>
                            <td><code>{@time}</code></td>
                            <td><?php _e( "The time the email is sent", "frontendupload" ); ?></td>
                        </tr>
                        <tr>
                            <td><code>{@ip}</code></td>
                            <td><?php _e( "The submitters IP address", "frontendupload" ); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        <script type="text/javascript">
            jQuery(document).ready(function(){
                jQuery('#feu-tags').hide();
                jQuery('a#feu-tags-toggle').click(function(){
                    jQuery('#feu-tags').slideToggle();
                    return false;
                });
            });
        </script>
        <style type="text/css">
            #feu-tags { max-width:500px; }
            #feu-tags table { width:100%; }
            #feu-tags table thead th { font-weight:bold; }
            #feu-tags table th,
            #feu-tags table td { padding-left:0; padding-bottom:2px; }
            #feu-tags table td code { font-size:1em; }
        </style>
    <?php }


    /**
     * Outputs the HTML used for the success message field
     *
     * @return void
     */
    function edit_success_message()
    {
        $settings = get_option( FEU_PREFIX . 'settings' );
        ?>
        <textarea rows="10" cols="50" class="large-text code" name="<?php echo FEU_PREFIX; ?>settings[success_message]"><?php echo !empty( $settings['success_message'] ) ? $settings['success_message'] : '<strong>Your files have been received.</strong>'; ?></textarea>
    <?php }


    /**
     * Enqueue Front End Upload assets
     *
     * @return void
     */
    function assets()
    {
        // add options menu
        add_options_page( 'Settings', 'Front End Upload', 'manage_options', __FILE__, array( 'FrontEndUpload', 'admin_screen_options' ) );
    }


    /**
     * Enqueue the Plupload assets
     *
     * @return void
     */
    function assets_public()
    {
        wp_enqueue_script( 'jquery' );
        wp_enqueue_script(
            'browserplus'
            ,'http://bp.yahooapis.com/2.4.21/browserplus-min.js'
            ,NULL
            ,FEU_VERSION
            ,FALSE
        );
        wp_enqueue_script(
            'feu-plupload'
            ,FEU_URL . '/lib/plupload/js/plupload.full.js'
            ,'jquery'
            ,FEU_VERSION
            ,FALSE
        );

        // plupload localization
        $locale = substr( get_locale(), 0, 2 );
        if( 'en' != $locale )
        {
            $plupload_translations = array( 'cs', 'da', 'de', 'es', 'fi', 'fr', 'it', 'ja', 'lv', 'nl', 'pt-br', 'ru', 'sv' );

            if( in_array( $locale, $plupload_translations ) )
            {
                wp_enqueue_script(
                    'feu-plupload-queue-i18n'
                    ,FEU_URL . '/lib/plupload/js/i18n/' . $locale . '.js'
                    ,'jquery'
                    ,FEU_VERSION
                    ,FALSE
                );
            }
        }

        wp_enqueue_script(
            'feu-plupload-queue'
            ,FEU_URL . '/lib/plupload/js/jquery.plupload.queue/jquery.plupload.queue.js'
            ,'jquery'
            ,FEU_VERSION
            ,FALSE
        );

        wp_enqueue_style(
            'feu-plupload'
            ,FEU_URL . '/lib/plupload/js/jquery.plupload.queue/css/jquery.plupload.queue.css'
            ,FEU_VERSION
        );
    }


    /**
     * Initializes Plupload on the front end
     *
     * @return void
     */
    function init_plupload()
    {
        // define our first hash
        $hash = uniqid( 'feuhash_' );
        set_transient( $hash, 1, 60*60*18 );

        // grab our salt
        $salt       = get_option( '_feufilesalt' );    // unique to each install
        $uploadflag = uniqid( 'feuupload_' );
        $uniqueflag = sha1( $salt . $uploadflag . $_SERVER['REMOTE_ADDR'] );
        set_transient( 'feuupload_' . $uploadflag, $uniqueflag, 60*60*18 );

        // handle our on-server location
        $url = 'http';
        if (isset($_SERVER['HTTPS']) && 'off' != $_SERVER['HTTPS'] && 0 != $_SERVER['HTTPS'])
            $url = 'https';
        $url .= '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];

        set_transient( 'feu_referer_' . md5( $url . $hash . $salt ), 1, 60*60*18 );

        // prep our max file size
        $settings = get_option( FEU_PREFIX . 'settings' );
        if( !empty( $settings ) && isset( $settings['max_file_size'] ) )
        {
            $max_file_size = intval( $settings['max_file_size'] );
        }
        else
        {
            $max_file_size = '10';
        }
        ?>
    <script type="text/javascript">
        var FEU_VARS = {
            destpath: '<?php echo FEU_URL; ?>',
            hash: '<?php echo $hash; ?>',
            uploadflag: '<?php echo $uploadflag; ?>',
            maxfilesize: '<?php echo $max_file_size; ?>',
            customext: <?php if( isset( $settings['custom_file_extensions'] ) && !empty( $settings['custom_file_extensions'] ) ) { ?>
                {title : "Other", extensions : "<?php echo $settings['custom_file_extensions']; ?>"}
                <?php } else { echo "null"; } ?>
        };
        var FEU_LANG = {
            email: '<?php _e( "You must enter a valid email address.", "frontendupload" ); ?>',
            min: '<?php _e( "You must queue at least one file.", "frontendupload" ); ?>'
        };
    </script>
    <?php
        wp_enqueue_script(
            'feu-env'
            ,FEU_URL . '/feu.js'
            ,'jquery'
            ,FEU_VERSION
            ,TRUE
        );
    }


    /**
     * Callback for Options screen
     *
     * @return void
     */
    function admin_screen_options() {
        include 'front-end-upload-options.php';
    }


    /**
     * Transients are used quite heavily to generate hashes, this function will essentially garbage collect them
     */
    function cleanup_transients() {
        global $wpdb, $_wp_using_ext_object_cache;

        if( $_wp_using_ext_object_cache )
            return;

        $time = isset ( $_SERVER['REQUEST_TIME'] ) ? (int) $_SERVER['REQUEST_TIME'] : time();
        $sql = "SELECT option_name FROM {$wpdb->options} WHERE ( option_name LIKE '_transient_timeout_feu_referer_%' AND option_value < {$time} ) OR ( option_name LIKE '_transient_timeout_feuhash_%' AND option_value < {$time} ) OR ( option_name LIKE '_transient_timeout_feuupload_feuupload_%' AND option_value < {$time} );";
        $expired = $wpdb->get_col( $sql );

        foreach( $expired as $transient ) {
            $key = str_replace( '_transient_timeout_', '', $transient );
            delete_transient( $key );
        }
    }


    /**
     * File uploads are obscured on disk, so we'll use a CPT to maintain proper file names
     */
    function register_storage() {
        $args = array(
            'public'    => FALSE,
            'supports'  => array( 'custom-fields' )
        );
        register_post_type( 'feu_file', $args );
    }


    /**
     * All downloads are (should be) routed through this function
     */
    function process_download() {

        global $post;

        $tmp_post = $post;

        $post = false;

        if( ( isset( $_GET['feu'] ) && (int) $_GET['feu'] === 1 ) && isset( $_GET['feuid'] ) ) {

            // our flag is the basis for this entire operation
            $feuid = sanitize_text_field( $_GET['feuid'] );

            // let's grab our corresponding post if we can
            $args = array(
                'numberposts'   => 1,
                'meta_key'      => 'feu_idhash',
                'meta_value'    => $feuid,
                'post_type'     => 'feu_file',
                'post_status'   => 'private',
                'cache_results' => FALSE,
                'no_found_rows' => TRUE,
            );

            $uploads = get_posts( $args );

            foreach ( $uploads as $post ) {

                setup_postdata( $post );

                $filepath = get_post_meta( $post->ID, 'feu_filepath', true );
                $filename = get_post_meta( $post->ID, 'feu_filename', true );

                if( $filepath && $filename ) {

                    // force the download

                    // required for IE
                    if ( ini_get( 'zlib.output_compression' ) ) { ini_set( 'zlib.output_compression', 'Off' ); }

                    // get the file mime type using the file extension
                    switch ( strtolower ( substr ( strrchr ( $filename, '.' ), 1 ) ) ) {
                        case 'pdf': $mime = 'application/pdf'; break;
                        case 'zip': $mime = 'application/zip'; break;
                        case 'jpeg':
                        case 'jpg': $mime = 'image/jpg'; break;
                        default: $mime = 'application/force-download';
                    }
                    header ( 'Pragma: public' );
                    header ( 'Expires: 0' );
                    header ( 'Cache-Control: must-revalidate, post-check=0, pre-check=0' );
                    header ( 'Last-Modified: ' . gmdate ( 'D, d M Y H:i:s', filemtime ( $filename ) ).' GMT' );
                    header ( 'Cache-Control: private', false );
                    header ( 'Content-Type: ' . $mime );
                    header ( 'Content-Disposition: attachment; filename="' . basename ( $filename ) . '"' );
                    header ( 'Content-Transfer-Encoding: binary' );
                    header ( 'Content-Length: ' . filesize ( $filepath ) );
                    header ( 'Connection: close' );
                    readfile ( $filepath );
                }

                exit();
            }
        }

        $post = $tmp_post;
    }

}

$front_end_upload = new FrontEndUpload();
