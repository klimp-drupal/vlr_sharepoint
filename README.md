CONTENTS OF THIS FILE
---------------------

 * Introduction
 * Requirements
 * Installation
 * Configuration
 * Maintainers
 
INTRODUCTION
------------

The VLR SharePoint module provides the Webform Handler plugin to submit a [Webform](https://www.drupal.org/project/webform) directly to SharePoint using the [SharePoint REST API](https://docs.microsoft.com/en-us/sharepoint/dev/sp-add-ins/get-to-know-the-sharepoint-rest-service). SharePoint credentials are stored using the [Key](https://www.drupal.org/project/key) module. Submitted data could be altered via hook_vlr_sharepoint_webform_data().

REQUIREMENTS
------------

This module requires the following modules:

 * Webform (https://www.drupal.org/project/webform)
 * Key (https://www.drupal.org/project/key)

INSTALLATION
------------

 * Install as you would normally install a contributed Drupal module. Visit
   https://www.drupal.org/node/1897420 for further information.
   
CONFIGURATION
-------------
 
 * Create a Key for the SharePoint authentication credentials, e.g. create a private file `sharepoint.json` with the following content:
     ```json
    {
        "username": "sharepointUsername@dummy.com",
        "password": "mySharepointPassword"
    }
    ```
    Refer to the Key (https://www.drupal.org/project/key) module documentation to find out about other ways to manage your keys.

 * Create a Webform. 

 * You may want to prevent storing the Webform submissions in your site's database. To do so navigate to the Webform's Settings » General tab and check the "Disable saving of submissions" checkbox.

 * Create a new "VLR Remote post" handler for your Webform.

   - Navigate to the Webform's Settings » Emails/Handlers tab.

   - Click "Add handler" and select "VLR Remote post".
   
   - Enter your SharePoint API entry point URL into the "Completed URL" field. 
   
     Refer to the [Sharepoint API](https://docs.microsoft.com/en-us/sharepoint/dev/sp-add-ins/get-to-know-the-sharepoint-rest-service) documentation to learn how to form an entry point.
   
   - Make sure all the fields that should be submitted to SharePoint are checked under "Submission data".
   
   - In the "SharePoint" fieldset:
   
        - Select the Key you have created for the SharePoint credentials.
   
        - Enter your SharePoint list name
        
        - If the field names in SharePoint are different from the Webform's field names enter the mapping in yaml format, e.g.:
        ```yaml
        webform_field_machine_name: SharepointNameForThisField
        webform_field_machine_name_2: SharepointNameForThisField2
        ```

MAINTAINERS
-----------

Current maintainers:
 * Nikolay Volodin (klimp) - https://www.drupal.org/u/klimp
