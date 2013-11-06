scripts
=======

mail_handler.php
----------------

Send invitation to event by email, send update about event change to remote attendee by email.
This script is separate by main code of **DAViCal** , because the mails
can be handled by cron.

### run

`php mail_handler.php`

### params

*       --fmail=path
        - read file with attendee email as vCalendar

*       --stdin
        - read stdin with attendee email as vCalendar

*       --invite-all
        - send invitation all remote attendee

*       --SERVER_NAME=example.org
        - name of server which is runing this script
        - important when you have more instacies of DAViCal in one server
        - and you have config.php separate for each server like config-example.org.php

*       --save-sent-invitation=false/true
        - default true, after send invitation is stored in db and no send more


### config

the config file is the same like for **DAViCal**

*   template
    - **true/false**
    - use template file
*   Reply-To
    - specify address for reply of invitation

#### example of config

`$c->MailHandler = array();
$c->MailHandler['template'] = true;
$c->MailHandler['Reply-To'] = 'invitation_email_handler@example.com';`


### template

invitation mail have template for invitation mail located in `mail_handler.php.html`