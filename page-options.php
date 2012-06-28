<?php
global $wp_locale;
// Display messages and errors
echo $this->get_messages_html();
?>
<div class="wrap">
    <?php screen_icon( 'options-general' ); ?>
    <h2><?php _e( 'Backup Settings', $this->text_domain ); ?></h2>
    <?php wp_nonce_field( 'closedpostboxes', 'closedpostboxesnonce', false ); ?>
    <?php wp_nonce_field( 'meta-box-order', 'meta-box-order-nonce', false ); ?>
    <div id="poststuff" class="metabox-holder<?php echo 2 == $screen_layout_columns ? ' has-right-sidebar' : ''; ?>">
        <div id="side-info-column" class="inner-sidebar">
            <?php do_meta_boxes( $this->pagehook, 'side', '' ); ?>
        </div>

        <form action="<?php echo admin_url( "options-general.php?page=backup&action=update" ); ?>" method="post">
            <?php wp_nonce_field( 'backup_options' ); ?>
            <input type="hidden" name="action" value="save_backup_options" />
            <div id="post-body" class="has-sidebar">
                <div id="post-body-content" class="has-sidebar-content">
                    <table class="form-table" style="margin-bottom: 8px">
                        <tbody>
                            <tr valign="top">
                                <th scope="row"><label for="local_folder"><?php _e( 'Local folder path', $this->text_domain ); ?></label></th>
                                <td>
                                    <input id="local_folder" name="local_folder" type="text" class="regular-text code" value="<?php echo esc_html( $this->options['local_folder'] ); ?>" />
                                    <p class="description"><?php _e( "Local backups as well as other files created by the plugin will be stored on this path.", $this->text_domain ) ?></p>
                                </td>
                            </tr>
                            <?php if ( $this->goauth->is_authorized() ) { ?>
                            <tr valign="top">
                                <th scope="row"><label for="drive_folder"><?php _e( 'Drive folder ID', $this->text_domain ); ?></label></th>
                                <td>
                                    <input id="drive_folder" name="drive_folder" type="text" class="regular-text code" value="<?php echo esc_html( $this->options['drive_folder'] ); ?>" />
                                    <p class="description"><?php _e( 'Backups will be uploaded to this folder. Leave empty to save in root folder.', $this->text_domain ) ?></p>
                                </td>
                            </tr>
                            <?php } ?>
                            <tr valign="top">
                                <th scope="row"><label for="local_number"><?php _e( 'Store a maximum of', $this->text_domain ); ?></label></th>
                                <td>
                                    <input id="local_number" name="local_number" type="number" min="0" step="1" class="small-text code" value="<?php echo intval( $this->options['local_number'] ); ?>" /><span> <?php _e( 'backup(s) locally', $this->text_domain ); ?></span>
                                    <?php if ( $this->goauth->is_authorized() ) { ?>
                                    <span> <?php _e( 'and', $this->text_domain ); ?> </span><input name="drive_number" type="number" min="0" step="1" class="small-text code" value="<?php echo intval( $this->options['drive_number'] ); ?>" /><span> <?php _e( 'backup(s) on Google Drive', $this->text_domain ); ?></span>
                                    <?php } ?>
                                </td>
                            </tr>
                            <tr valign="top">
                                <th scope="row"><label for="backup_frequency"><?php _e( 'When to back up', $this->text_domain ); ?></label></th>
                                <td>
                                    <select id="backup_frequency" name="backup_frequency">
                                        <option value="never" <?php selected( 'never', $this->options['backup_frequency'] ); ?> ><?php _e( 'Never', $this->text_domain ); ?></option>
                                        <option value="daily" <?php selected( 'daily', $this->options['backup_frequency'] ); ?> ><?php _e( 'Daily', $this->text_domain ); ?></option>
                                        <option value="weekly" <?php selected( 'weekly', $this->options['backup_frequency'] ); ?> ><?php _e( 'Weekly', $this->text_domain ); ?></option>
                                        <option value="monthly" <?php selected( 'monthly', $this->options['backup_frequency'] ); ?> ><?php _e( 'Monthly', $this->text_domain ); ?></option>
                                    </select>
                                    <div id="start_day_div" class="hide-if-js">
                                        <label for="start_day"> <?php _e( "starting on", $this->text_domain ); ?> </label>
                                        <select id="start_day" name="start_day">
                                            <?php
                                            for ($day_index = 0; $day_index <= 6; $day_index++)
                                                echo '<option value="' . esc_attr($day_index) . '">' . $wp_locale->get_weekday($day_index) . '</option>';
                                            ?>
                                        </select>
                                    </div>
                                    <div id="start_time_div" class="hide-if-js">
                                        <label for="start_hour"> <?php _e( "at", $this->text_domain ); ?> </label>
                                        <input id="start_hour" name="start_hour" type="number" min="0" max="23" step="1" class="small-text code" value="0" /><span>:</span><input id="start_minute" name="start_minute" type="number" min="0" max="59" step="1" class="small-text code" value="0" />
                                        <div class="description"><?php printf( __( "Select %s if you want to add a cron job to do backups.", $this->text_domain ), "<kbd>" . __( "never", $this->text_domain ) . "</kbd>" ); ?></div>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <?php do_meta_boxes( $this->pagehook, 'normal', '' ); ?>
                    <p class="submit">
                        <input name="submit" type="submit" class="button-primary" value="<?php _e( 'Save changes', $this->text_domain ) ?>" />
                    </p>
                    <?php do_meta_boxes( $this->pagehook, 'advanced', '' ); ?>
                </div>
                <br class="clear"/>
            </div>
        </form>
    </div>
</div>
<script type="text/javascript">
    //<![CDATA[
    jQuery(document).ready( function($) {
        $('.if-js-closed').removeClass('if-js-closed').addClass('closed');
        postboxes.add_postbox_toggles('<?php echo $this->pagehook; ?>');
        var val = "";
        $('#backup_frequency').change(function(){
            val = $("#backup_frequency option:selected").val();
            switch(val) {
                case "never":
                    $("#start_day_div, #start_time_label").addClass("hide-if-js");
                    break;
                case "daily":
                    $("#start_day_div").addClass("hide-if-js");
                    $("#start_time_div").removeClass("hide-if-js");
                    break;
                default:
                    $("#start_day_div, #start_time_label").removeClass("hide-if-js");
            }
        });
    });
    //]]>
</script>