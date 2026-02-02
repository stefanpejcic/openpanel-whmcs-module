# OpenPanel WHMCS Module 😎
WHMCS module for [OpenPanel](https://openpanel.com)


## Requirements

- Server with OpenPanel Enterprise license
- WHMCS

## Installation

1. Login to SSH for WHMCS server
2. Navigate to `path_to_whmcs/modules/servers`
3. Run this command to create a new folder and in it download the module:
   ```bash
   git clone https://github.com/stefanpejcic/openpanel-whmcs-module.git openpanel
   ```

## Configuration

How to setup WHMCS and OpenPanel: https://openpanel.com/docs/articles/extensions/openpanel-and-whmcs/

##  Troubleshooting

1. WHMCS Module Log:
 - Triggered when OPENPANEL_DEBUG is enabled.
 - Logs requests to the WHMCS database.
 - Accessible via WHMCS Admin: /admin/index.php?rp=/admin/logs/module-log

2. OpenAdmin API Log:
 - Triggered when DEV_MODE is enabled on OpenAdmin:
   `opencli config update dev_mode on && service admin restart`
 - Logs incoming API requests.
 - Stored at: /var/log/openpanel/admin/api.log


## Update

1. Login to SSH for WHMCS server
2. Navigate to `path_to_whmcs/modules/servers/openpanel`
3. Run this command to download newer files:
   ```bash
   git pull
   ```

## Bug Reports

Report [new issue on github](https://github.com/stefanpejcic/openpanel-whmcs-module/issues/new/choose)

