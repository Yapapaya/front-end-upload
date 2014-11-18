=== Front End Upload ===
Contributors: saurabhshukla
Tags: upload, front end, uploader, frontend, file, files, media, audio, video, photos, images, pictures, photo, image, picture, form, user upload 
Requires at least: 3.2
Tested up to: 3.4.2
Stable tag: 0.6.1

Provides the most basic implementation allowing site visitors to upload files to the Media library and notify admin ME! This plugin is in desperate need of some TLC from a developer with enough time to make that happen.

== Description ==

**ADOPTED!** This plugin has been adopted. We shall return to normalcy, shortly.

= Uploading files should be considered risky =

This plugin will facilitate uploading files to your server, which by nature **should be considered risky**.

**MAKE SURE** you have taken the proper precautions in protecting your uploads folder from prying eyes and malicious
intent. At the very least make sure you've uploaded an empty index.html or index.php to prevent directory listing.
Front End Upload takes a number of precautions to hopefully prevent unwanted file uploads but please be mindful that
server configuration can help prevent unwanted outcomes.

== Installation ==

1. Prevent directory listing of your uploads directory (e.g. add index.html or index.php to wp-content/uploads)
1. Ensure proper security measures are taken to prevent code execution (e.g. add `php_flag engine off` to .htaccess
within wp-content/uploads)
1. Implement other security measures you feel necessary to protect your wp-content/uploads directory from prying eyes
 or malicous intent
1. Upload the `front-end-upload` folder to your `/wp-content/plugins/` directory
1. Activate the plugin through the 'Plugins' menu in WordPress
1. Edit your options under **Settings > Front End Upload**
1. Place `[front-end-upload]` in the editor where you would like the uploader to appear
1. Customize your CSS

== Screenshots ==

1. The Front End Upload Options screen

== Changelog ==

= 0.6.1 =
* Updated Plupload to version 1.5.5

= 0.6 =
* Refactored the way file storage happens. Uploaded files are now obscured and
  downloads are routed through the plugin so as to help prevent unwanted execution of uploaded files
* Elaborated installation instructions

= 0.5.4.6 =
* Fixed a JavaScript issue that submitted the form too soon preventing accurate file
  locations from being sent in the resulting email in certain browsers
* Fixed an issue where a false positive security check would prevent files from saving

= 0.5.4.5 =
* Additional security precautions to better validate submissions (props Chris Kellum)

= 0.5.4.3 =
* Additional security precautions to better validate submissions to upload.php

= 0.5.4.2 =
* Added in additional file extension check

= 0.5.4.1 =
* Further security enhancement to better obfuscate file locations

= 0.5.4 =
* Fixed a security threat that allowed for potential code execution. **Upgrade right away**. This was not an issue in Front End Upload Pro.

= 0.5.3 =
* Updated Polish translation
* Better handling of larger uploads in Safari
* Tested with WordPress 3.4RC1

= 0.5.2 =
* Added a unique hash to the destination directory that will increase security by obscurity
* Upgraded Plupload to 1.5.4

= 0.5.1 =
* Removed a rogue PHP short open tag (props wzielins)
* Updated file paths to accommodate multisite
* Upgraded Plupload to 1.5.2

= 0.5 =
* Fixed potential issue(s) with directory/URL definition (props https://github.com/davidmh)
* Disabled unique filename requirement

= 0.4 =
* Added {@ip} to the available message tags
* Added more opportunities for localization

= 0.3 =
* Completely changed the way uploads are handled. They are no longer sent to the WordPress Media library and are instead stored in a subdirectory of `~/wp-content/uploads/`
* If a passcode is set, it must now be entered before any uploading can take place
* Email validation takes place via JavaScript prior to the upload beginning
* Support for large files has been added

= 0.2 =
* Added custom mime types to the options screen
* Fixed potential issue with unset max file size

= 0.1 =
* Initial release
