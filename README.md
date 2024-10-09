# me.sxs.square

![Screenshot](/images/screenshot.png)

Still in alpha version, but the principal is there and working.
This CiviCRM extension add a new payment processor gateway.

Flowchart (alpha version)

~~Before installation, you need to configure your SQUARE_ACCESS_TOKEN as an environment variable.~~
~~Under, ngix, this is done in /etc/nginx/fastcgi.config.~~

Upon installation, the extension check for the following CiviCRM entities
 - a) Financial account named Square Account, and create it when not found
 - b) Payment instrument named Square terminal, and create it when not found
 - c) Payment processor type named Square terminal, and create it when not found
 - d) Does not create the payment gateway per see, must be done manually
~~ - e) A webhook from Square to the CiviCRM listener endpoint, and create it when not found~~

Payment Gateway need to be setup manually.  Extension will check for the presence of required values.

When used to process a payment, the extension 
- Check for a webhook from Square to the CiviCRM listener endpoint, and create it when not found
- Hide the form part that collect credit card information.
- Print instruction to proceed to square terminal.
- Create an OPEN order in Square terminal to the first current location (need to addressed).
- Convert this Square order into an invoice in order to be paid for.
- Publish the invoice in order to be seen at the terminal

CiviCRM wait for the webhook message that confirm the invoice have been paid for.
Still some work to be done here to change status from pending to completed.

This extension is only for Square in person transaction.

It have not been yet tested for other things thant public event registration.  Preliminar testing seem it also work 
with backoffice event registration.

More testing need to be done.

The extension code need to be peer review to conform to best practice coding, CiviCRM standard and add automatic 
code testing.

Any help is welcome.  Use at your own risk.



The extension is licensed under [AGPL-3.0](LICENSE.txt).

## Requirements

* PHP v8.0+
* CiviCRM 5.75 or later

## Installation

Download the `.zip` file and expand it in wp-content/uploads/civicrm/ext.
You need composer to install the Square SDK and use it. (to be confirmed)

See the section below for more information on how to create a Square Account Token.

## Square Account Setup

https://developer.squareup.com/console/

- You need your Square account credentials, or to create an account if you don't have one.
- Register a new application (type "Accept Payments")
- Audience: you can skip
- The Access Token will then be displayed

Square does not let you delete applications, but you can rename them.

## Getting Started

Start play with this and give me feedback on most needed improvment.

## Known Issues

- Completed to order to invoice conversion process
- Need to fix return code in case of error
- Need list of completed task in case of error
- Keep trace of idem potency key for retry in case of error
