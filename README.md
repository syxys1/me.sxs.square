# me.sxs.square

![Screenshot](/images/screenshot.png)

Still in alpha version, but the principal is there and working.
This CiviCRM extension add a new payment processor gateway.

Flowchart (alpha version)

Before installation, you need to configure your SQUARE_ACCESS_TOKEN as an environment variable.
Under, ngix, this is done in /etc/nginx/fastcgi.config.

Upon installation, the extension check for the following CiviCRM entities
 a) Financial account named Square Account, and create it when not found
 b) Payment instrument named Square terminal, and create it when not found
 c) Payment processor type named Square terminal, and create it when not found
 d) Does not create the payment gateway per see, must be done manually
 e) A webhook from Square to the CiviCRM listener endpoint, and create it when not found

When used to process a payment, the extension hide form part to collect credit card information.
Print instruction to proceed to square terminal.
The extension create an OPEN order in Square terminal to the first current location (need to addressed).
Then convert this Square order into an invoice in order to be paid for.

CiviCRM wait for the webhook message that confirm the invoice have been paid for.
Still some work to be done here to change status from pending to completed.

This extension is only for Square in person transaction.

It have not been yet tested for other things thant public event registration.  Preliminar testing seem it also work 
with backoffice event registration.

More testing need to be done.

The extension code need to be peer review to conform to best practice coding, CiviCRM standard and add automatic 
code testing.

Any help is welcome.  Use at your own risk.

Upon second installation, the extension check for following CiviCRM entities


The extension is licensed under [AGPL-3.0](LICENSE.txt).

## Requirements

* PHP v7.4+      to be confirmed (currentl work on 8.2)
* CiviCRM (7.X)  to be confirmed (curently work on 7.5.0) 
* WP 


## Manual Installation

This is the only supported installation method for know.
Download the `.zip` file and expand it in wp-content/uploads/civicrm/ext.
You need composer to install the Square SDK and use it. (to be confirmed)


## Installation (Web UI)

Learn more about installing CiviCRM extensions in the [CiviCRM Sysadmin Guide](https://docs.civicrm.org/sysadmin/en/latest/customize/extensions/).

## Installation (CLI, Zip)

Sysadmins and developers may download the `.zip` file for this extension and
install it with the command-line tool [cv](https://github.com/civicrm/cv).

```bash
cd <extension-dir>
cv dl me.sxs.square@https://github.com/FIXME/me.sxs.square/archive/master.zip
```
or
```bash
cd <extension-dir>
cv dl me.sxs.square@https://lab.civicrm.org/extensions/me.sxs.square/-/archive/main/me.sxs.square-main.zip
```

## Installation (CLI, Git)

Sysadmins and developers may clone the [Git](https://en.wikipedia.org/wiki/Git) repo for this extension and
install it with the command-line tool [cv](https://github.com/civicrm/cv).

```bash
git clone https://github.com/FIXME/me.sxs.square.git
cv en square
```
or
```bash
git clone https://lab.civicrm.org/extensions/me.sxs.square.git
cv en square
```

## Getting Started

Start play with this and give me feedback on most needed improvment.

## Known Issues

(* FIXME *)
