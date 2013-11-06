scripts
=======

mail_handler.php
----------------

Send invitation to event by email, send update about event change to remote attendee by email.
This script is separate by main code of **DAViCal** , because the mails
can be handled by cron.

### run

simple

`php mail_handler.php`

sent invitation or update of mail

`php mail_handler.php --invite-all`

handle reply of invitation

`cat /var/mails/email_with_example_reply_of_invitation.eml | php mail_handler.php --stdin`
`php mail_handler.php --file=/var/mails/email_with_example_reply_of_invitation.eml`

### params

*   --fmail=path
    - read file with attendee email as vCalendar
*   --stdin
    - read stdin with attendee email as vCalendar
*   --invite-all
    - send invitation all remote attendee
*   --SERVER_NAME=example.org
    - name of server which is runing this script
    - important when you have more instacies of DAViCal in one server
    - and you have config.php separate for each server like config-example.org.php
*   --save-sent-invitation=false/true
    - default true, after send invitation is changed status in db and no send again
    - good for debug



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