<?php $skipJSsettings = 1; ?>
<?php require_once('common.php'); ?>
<?php
//TODO Backup/Restore of events, scripts and playlists
//TODO Backup/Restore of plugin settings
//TODO Backup/Restore of WLAN interface settings (could be useful for cloning devices)
//TODO Download/Restore from backups stored on device USB stick
//TODO Change logging (new and old value of setting where applicable)

//Define a map of backup/restore areas and setting locations, this is also used to populate the area select lists
$system_config_aras = array(
    'all' => array('friendly_name' => 'All', 'file' => false),
    'channelOutputsJSON' => array('friendly_name' => 'Channel Outputs (RGBMatrix)', 'file' => $settings['channelOutputsJSON']),
    'channeloutputs' => array('friendly_name' => 'Channel Outputs (Other eg, LOR, Renard)', 'file' => $settings['channelOutputsFile']),
    'channelmemorymaps' => array('friendly_name' => 'Channel Memory Maps', 'file' => $settings['channelMemoryMapsFile']),
    'email' => array('friendly_name' => 'Email', 'file' => false),
    'pixelnetDMX' => array('friendly_name' => 'Falcon Pixlenet/DMX', 'file' => $pixelnetFile),
    'schedule' => array('friendly_name' => 'Schedule', 'file' => $scheduleFile),
    'settings' => array('friendly_name' => 'Settings', 'file' => $settingsFile),
    'timezone' => array('friendly_name' => 'Timezone', 'file' => $timezoneFile),
    'universes' => array('friendly_name' => 'Universes', 'file' => $universeFile),
//    'wlan' => array('friendly_name' => 'WLAN', 'file' => $settings['configDirectory']. "/interface.wlan0")
);

//Array of plugins and
$system_active_plugins = array();

//Preserve some existing settings by default
$keepMasterSlaveSettings = true;
$keepNetworkSettings = true;

//list of settings restored
$settings_restored = array();
//restore done state
$restore_done = false;

/**
 * Handle POST for download or restore
 * Check which submit button was pressed
 */
if (isset($_POST['btnDownloadConfig'])) {
    //////
    /// BACKUP
    /////
    if (isset($_POST['backuparea']) && !empty($_POST['backuparea'])) {
        //this value *SHOULD* directly match a key in $system_config_aras
        $area = $_POST['backuparea'];

        //temp data
        $tmp_settings_data = array();

        if (array_key_exists($area, $system_config_aras)) {

            //Create a copy of the areas array to manipulate
            $tmp_config_areas = $system_config_aras;

            //Generate backup for selected area
            if (strtolower($area) == "all") {
                //combine all backup areas into a single file

                //remove the 'all' key and do some processing of areas array
                unset($tmp_config_areas['all']);
                //remove email as its in the general settings file and not a seperate section
                unset($tmp_config_areas['email']);

                foreach ($tmp_config_areas as $config_key => $config_data) {
                    $setting_file = $config_data['file'];

                    if ($setting_file !== false && file_exists($setting_file)) {
                        if ($config_key == "settings") {
                            //parse ini properly
                            $file_data = parse_ini_string(file_get_contents($setting_file));
                            $file_data['emailgpass'] = '';//remove email pass for now,
                        } else if ($config_key == "channelOutputsJSON") {
                            //channelOutputsJSON is a formatted (prettyPrint) JSON file, decode it into an assoc. array
                            $file_data = json_decode(file_get_contents($setting_file), true);
                        } else {
                            //all other files are std flat files, process them into an array by splitting at line breaks
                            $file_data = explode("\n", file_get_contents($setting_file));
                        }

                        $tmp_settings_data[$config_key] = $file_data;
                    }
                }
            } else if (strtolower($area) == "email") {
                //special treatment for email settings as they are inside the general settings file, emailgpass can't be obtained directly though..
                $email_settings = array(
                    'emailenable' => $settings['emailenable'],
                    'emailguser' => $settings['emailguser'],
//                    'emailgpass' => false, //Not available to us unless we directly pull it from the settings file
                    'emailfromtext' => $settings['emailfromtext'],
                    'emailtoemail' => $settings['emailtoemail']
                );

                $tmp_settings_data['email'] = $email_settings;
            } else {
                //All other backup areas
                $setting_file = $tmp_config_areas[$area]['file'];

                if ($setting_file !== false && file_exists($setting_file)) {
                    if ($area == "settings") {
                        //parse ini properly into an assoc. array
                        $file_data = parse_ini_string(file_get_contents($setting_file));
                        $file_data['emailgpass'] = '';//remove email pass for now,
                    } else if ($area == "channelOutputsJSON") {
                        //channelOutputsJSON is a formatted (prettyPrint) JSON file, decode it into an assoc. array
                        $file_data = json_decode(file_get_contents($setting_file), true);
                    } else {
                        //all other files are std flat files, process them into an array by splitting at line breaks
                        $file_data = explode("\n", file_get_contents($setting_file));
                    }

                    $tmp_settings_data[$area] = $file_data;
                }
            }

            //DO IT!
            if (!empty($tmp_settings_data)) {
                do_backup_download($tmp_settings_data, $area);
            }
        }
    }

} else if (isset($_POST['btnRestoreConfig'])) {
    //////
    /// RESTORE
    /////
    if (isset($_POST['restorearea']) && !empty($_POST['restorearea'])) {
        $restore_area = $_POST['restorearea'];

        if (isset($_POST['keepExitingNetwork']) && !empty($_POST['keepExitingNetwork'])) {
            $keepNetworkSettings = $_POST['keepExitingNetwork'];
        }
        if (isset($_POST['keepMasterSlave']) && !empty($_POST['keepMasterSlave'])) {
            $keepMasterSlaveSettings = $_POST['keepMasterSlave'];
        }

        //this value *SHOULD* directly match a key in $system_config_aras
        if (array_key_exists($restore_area, $system_config_aras)) {
            //Do something with the uploaded file to restore it
            if (array_key_exists('conffile', $_FILES)) {
                //data is stored by area and keyed the same as $system_config_aras
                //read file, decode json
                //parse each area and write settings

                $rstfname = $_FILES['conffile']['name'];
                $rstftmp_name = $_FILES['conffile']['tmp_name'];
                //file contents
                $file_contents = file_get_contents($rstftmp_name);
                //decode back into an array
                $file_contents_decoded = json_decode($file_contents, true);

                //successful decode
                if ($file_contents_decoded !== FALSE && is_array($file_contents_decoded)) {
                    if (strtolower($restore_area) == "all") {
                        // ALL SETTING RESTORE
                        //read each area and process it
                        foreach ($file_contents_decoded as $restore_area_key => $area_data) {
                            process_restore_data($restore_area_key, $area_data);
                        }
                    } else if (strtolower($restore_area) == "email") {
                        // EMAIL SETTING RESTORE
                        if (array_key_exists('email', $file_contents_decoded)) {
                            $emailenable = $file_contents_decoded['email']['emailenable'];
                            $emailguser = $file_contents_decoded['email']['emailguser'];
                            $emailgpass = "";//Not available when doing anything except full backups
                            $emailfromtext = $file_contents_decoded['email']['emailfromtext'];
                            $emailtoemail = $file_contents_decoded['email']['emailtoemail'];

                            //Write them out
                            WriteSettingToFile('emailenable', $emailenable);
                            WriteSettingToFile('emailguser', $emailguser);
//                            WriteSettingToFile('emailgpass', $emailgpass);
                            WriteSettingToFile('emailfromtext', $emailfromtext);
                            WriteSettingToFile('emailtoemail', $emailtoemail);

                            //Update the email config (writes out exim config)
                            SaveEmailConfig($emailguser, $emailgpass, $emailfromtext, $emailtoemail);

                            $settings_restored[$restore_area] = true;
                        } else if (array_key_exists('settings', $file_contents_decoded)) {
                            $emailenable = $file_contents_decoded['settings']['emailenable'];
                            $emailguser = $file_contents_decoded['settings']['emailguser'];
                            $emailgpass = $file_contents_decoded['settings']['emailgpass'];
                            $emailfromtext = $file_contents_decoded['settings']['emailfromtext'];
                            $emailtoemail = $file_contents_decoded['settings']['emailtoemail'];

                            //Write them out
                            WriteSettingToFile('emailenable', $emailenable);
                            WriteSettingToFile('emailguser', $emailguser);
                            WriteSettingToFile('emailgpass', $emailgpass);
                            WriteSettingToFile('emailfromtext', $emailfromtext);
                            WriteSettingToFile('emailtoemail', $emailtoemail);
                            //Update the email config (writes out exim config)
                            SaveEmailConfig($emailguser, $emailgpass, $emailfromtext, $emailtoemail);

                            $settings_restored[$restore_area] = true;
                        }
                    } else {
                        // ALL OTHER SETTING RESTORE
                        //Process specific restore areas, this work almost like the 'all' area
                        //general settings, but only a matching area is cherry picked

                        //If the key exists in the decoded data
                        if (array_key_exists($restore_area, $file_contents_decoded)) {
                            $restore_area_key = $restore_area;
                            $area_data = $file_contents_decoded[$restore_area_key];
                            process_restore_data($restore_area, $area_data);
                        }
                    }

                    //All processed
                    $restore_done = true;
                } else {
                    error_log("The backup " . $rstfname . " could not be decoded properly. Is it a valid backup file?");
                }
            }
        }
    }
}


/**
 * Function to look after backup restorations
 * @param $restore_area String Area to restore
 * @param $area_data Array Area data as an array
 */
function process_restore_data($restore_area, $area_data)
{
    global $system_config_aras, $keepMasterSlaveSettings, $keepNetworkSettings, $settings_restored;
    global $args;

    require_once('fppjson.php');
    require_once('fppxml.php');

    $restore_area_key = $restore_area;
    $save_result = false;

    if ($restore_area_key == "channelOutputsJSON") {
        $channel_outputs_json_filepath = $system_config_aras['channelOutputsJSON']['file'];
        //PrettyPrint the JSON data and save it
        $json_pp_data = prettyPrintJSON(json_encode($area_data));
        $save_result = file_put_contents($channel_outputs_json_filepath, $json_pp_data);
    }

    if ($restore_area_key == "channeloutputs") {
        //Overwrite channel outputs JSON
        $channel_outputs_filepath = $system_config_aras['channeloutputs']['file'];
        //implode array into string and reinsert new lines (reverse of backup explode)
        $data = implode("\n", $area_data);
        $save_result = file_put_contents($channel_outputs_filepath, $data);
    }

    if ($restore_area_key == "channelmemorymaps") {
        //Overwrite channel outputs JSON
        $channelmemorymaps_filepath = $system_config_aras['channelmemorymaps']['file'];
        $data = implode("\n", $area_data);
        $save_result = file_put_contents($channelmemorymaps_filepath, $data);
    }

    if ($restore_area_key == "schedule") {
        //Overwrite the schedule file and bump FPPD to reload it
        $schedule_filepath = $system_config_aras['schedule']['file'];
        $data = implode("\n", $area_data);
        if (!empty($data)) {
            $save_result = file_put_contents($schedule_filepath, $data);
            FPPDreloadSchedule();
        } else {
            //no data
            $save_result = true;
        }
    }

    if ($restore_area_key == "settings") {
//        $data = implode("\n", $area_data);
//        var_dump($area_data);
//        $ini_string = parse_ini_string($data);

        if (is_array($area_data)) {
            foreach ($area_data as $setting_name => $setting_value) {
                //check if we can change it (default value is checked - true)
                if ($setting_name == "fppMode") {
                    if ($keepMasterSlaveSettings == false) {
                        WriteSettingToFile($setting_name, $setting_value);
                    }
                } else {
                    WriteSettingToFile($setting_name, $setting_value);
                }

                //Do special things that require some sort of system change
                //eg. changing piRTC via GUI will fire off a shell command to set it up
                //we'll also do this to keep consistency
                if ($setting_name == 'piRTC') {
                    SetPiRTC($setting_value);
                } else if ($setting_name == "PI_LCD_Enabled") {
                    if ($setting_value == 1) {
                        $_GET['enabled'] = "true";
                    } else {
                        $_GET['enabled'] = "false";
                    }
                    SetPiLCDenabled();
                } else if ($setting_name == "AudioOutput") {
                    $args['value'] = $setting_value;
                    SetAudioOutput();
                } else if ($setting_name == "volume") {
                    $_GET['volume'] = trim($setting_value);
                    SetVolume();
                } else if ($setting_name == "NTP") {
                    SetNTPState($setting_value);
                }
            }

            $save_result = true;
        } else {
            error_log("RESTORE: Cannot read Settings INI settings. Attempted to parse " . json_encode($area_data));
        }
    }

    if ($restore_area_key == "timezone") {
        $data = $area_data[0];//first index has the timezone, index 1 is empty to due carrage return in file when its backed up
        SetTimezone($data);
        $save_result = true;
    }

    if ($restore_area_key == "pixelnetDMX") {
        //Just overwrite the universes file
        $pixlnet_filepath = $system_config_aras['pixelnetDMX']['file'];
        $data = implode("\n", $area_data);
        $save_result = file_put_contents($pixlnet_filepath, $data);
    }

    if ($restore_area_key == "universes") {
        //Just overwrite the universes file
        $universe_filepath = $system_config_aras['universes']['file'];
        $data = implode("\n", $area_data);
        $save_result = file_put_contents($universe_filepath, $data);
    }

    if ($save_result) {
        $settings_restored[$restore_area] = true;
    } else {
        $settings_restored[$restore_area] = false;
        error_log("RESTORE: Failed to restore " . $restore_area . " ");
    }
}

/**
 * Searches the $settings array to generate a list of active plugins
 * @return Array Assoc. array of plugins keyed by plugin_name
 */
function listPlugins()
{
    return null;
}

/**
 * Sets the timezone (taken from timeconfig.php)
 * @param $timezone_setting String Timezone in correct format
 * @see https://en.wikipedia.org/wiki/List_of_tz_database_time_zones For a list of timezones
 */
function SetTimezone($timezone_setting)
{
    global $mediaDirectory, $SUDO;
    //TODO: Check timezone for validity
    $timezone = $timezone_setting;
    error_log("RESTORE: Changing timezone to '" . $timezone . "'.");
    exec($SUDO . " bash -c \"echo $timezone > /etc/timezone\"", $output, $return_val);
    unset($output);
    //TODO: check return
    exec($SUDO . " dpkg-reconfigure -f noninteractive tzdata", $output, $return_val);
    unset($output);
    //TODO: check return
    exec(" bash -c \"echo $timezone > $mediaDirectory/timezone\"", $output, $return_val);
    unset($output);
    //TODO: check return
}

/**
 * Sets the PiRTC setting exec's the appropriate command (taken from settings.php)
 * @param $pi_rtc_setting String PIRTC setting
 */
function SetPiRTC($pi_rtc_setting)
{
    global $SUDO, $fppDir;

    $piRTC = $pi_rtc_setting;
    WriteSettingToFile("piRTC", $piRTC);
    exec($SUDO . " $fppDir/scripts/piRTC set");
    error_log("RESTORE: Set RTC:" . $piRTC);
}

/**
 * Starts the download in the browser
 * @param $settings_data Array Assoc. Array of settings data
 * @param $area String Area the download was for
 */
function do_backup_download($settings_data, $area)
{
    global $settings;

    if (!empty($settings_data)) {
        //Once we have all the settings, process the array and dump it back to the user
        //filename
        $backup_fname = $settings['HostName'] . "_" . $area . "-backup_" . date("YmdHis") . ".json";
        $backup_local_fpath = $settings['configDirectory'] . "/" . $backup_fname;

        //Write data into backup file
        file_put_contents($backup_local_fpath, json_encode($settings_data));

        ///Generate the headers to prompt browser to start download
        header("Content-Disposition: attachment; filename=\"" . $backup_fname . "\"");
        header("Content-Type: application/json");
        header("Content-Length: " . filesize($backup_local_fpath));
        header("Connection: close");
        //Output the file
        readfile($backup_local_fpath);
        //die
        exit;
    } else {
        //no data supplied
        error_log("BACKUP: Something went wrong while generating backup file for " . $area . ", no data was supplied.");
    }
}

/**
 * Generate backup/restore area select list
 * @param $area_name String Either 'backuparea' or 'restorearea' for either respective section
 * @return string HTML code for a dropdown selection list
 */
function backup_gen_select($area_name = "backuparea")
{
    global $system_config_aras;

    $select_html = "<select name=\"$area_name\" id=\"$area_name\">";
    foreach ($system_config_aras as $item => $item_options) {
        $select_html .= "<option value=" . $item . ">" . $item_options['friendly_name'] . "</option>";
    }
    $select_html .= "</select>";

    return $select_html;
}

?>

<!DOCTYPE html>
<html>
<head>
    <?php include 'common/menuHead.inc'; ?>
    <title><? echo $pageTitle; ?></title>
    <script>var helpPage = "help/backup.php";</script>
</head>
<body>
<div id="bodyWrapper">
    <?php include 'menu.inc'; ?>
    <br/>

    <form action="backup.php" method="post" name="frmBackup" enctype="multipart/form-data">
        <div id="global" class="settings">
            <fieldset>
                <legend>FPP Settings Backup</legend>
                <?php if ($restore_done == true) {
                    ?>
                    <div id="restoreSuccessFlag">Backup Restored, A Reboot May Be Required.</div>
                    <div id="restoreSuccessFlag">What was
                        restored:
                        <?php
                        foreach ($settings_restored as $area_restored => $success) {
                            $success_str;
                            if ($success == true) {
                                $success_str = " Success";
                            } else {
                                $success_str = " Failed";
                            }
                            echo $area_restored . " - " . $success_str . "<br/>";
                        }
                        ?>
                    </div>
                <?php
                }
                ?>
                <fieldset>
                    <legend>Backup Configuration</legend>
                    Click this button to download the selected system configuration in JSON format.
                    <br/>

                    <table width="100%">
                        <tr>
                            <td width="25%">Backup Area</td>
                            <td width="75%"><?php echo backup_gen_select('backuparea'); ?></td>
                        </tr>
                        <tr>
                            <td width="25%"></td>
                            <td width="75%">
                                <input name="btnDownloadConfig" type="Submit" style="width:30%" class="buttons"
                                       value="Download Configuration">
                            </td>
                        </tr>

                    </table>
                </fieldset>
                <br/>
                <fieldset>
                    <legend>Restore Configuration</legend>
                    Open a configuration JSON file and click the button below to restore the
                    configuration data to the selected area.
                    <br/>
                    <table width="100%">

                        <!--                        <tr>-->
                        <!--                            <td width="35%"><b>Keep Existing Network Settings</b></td>-->
                        <!--                            <td width="65%">-->
                        <!--                                <input name="keepExitingNetwork"-->
                        <!--                                       type="checkbox"-->
                        <!--                                       checked="true">-->
                        <!--                            </td>-->
                        <!--                        <tr/>-->

                        <tr>
                            <td width="35%"><b>Keep Existing Master/Slave Settings</b></td>
                            <td width="65%">
                                <input name="keepMasterSlave"
                                       type="checkbox"
                                       checked="true">
                            </td>
                        </tr>

                        <tr>
                            <td width="25%">Restore Area</td>
                            <td width="75%">
                                <?php echo backup_gen_select('restorearea'); ?></td>
                        </tr>

                        <tr>
                            <td width="25%"></td>
                            <td width="75%">
                                <input name="conffile" type="file" class="formbtn" id="conffile" size="50"
                                       autocomplete="off"></td>
                        </tr>

                        <tr>
                            <td width="25%"></td>

                            <td width="75%">
                                <input name="btnRestoreConfig" type="Submit" style="width:30%" class="buttons"
                                       value="Restore  Configuration">
                            </td>
                        </tr>
                    </table>
                </fieldset>
            </fieldset>
        </div>
    </form>

    <?php include 'common/footer.inc'; ?>
</body>
</html>