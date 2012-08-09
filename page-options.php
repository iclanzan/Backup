<div class="wrap">
	<?php screen_icon( 'options-general' ); ?>
	<h2><?php _e( 'Backup Settings', $this->text_domain ); ?> <?php echo '<a id="need-help-link" class="add-new-h2" href="#contextual-help-wrap">' . __( "Need help?", $this->text_domain ) . '</a>'; ?></h2>
	<div id="poststuff">
		<div id="post-body" class="metabox-holder columns-<?php echo $screen_layout_columns; ?>">
			<form action="<?php echo admin_url( "options-general.php?page=backup&action=update" ); ?>" method="post">
				<?php wp_nonce_field( 'closedpostboxes', 'closedpostboxesnonce', false ); ?>
				<?php wp_nonce_field( 'meta-box-order', 'meta-box-order-nonce', false ); ?>
				<?php wp_nonce_field( 'backup_options' ); ?>
				<div id="post-body-content">
					<table class="form-table" style="margin-bottom: 8px">
						<tbody>
							<tr valign="top">
								<th scope="row"><label for="backup_title"><?php _e( 'Backup title', $this->text_domain ); ?></label></th>
								<td>
									<input id="backup_title" name="backup_title" type="text" class="regular-text code" value="<?php echo esc_html( $this->options['backup_title'] ); ?>" />
									<p class="description"><?php _e( "This determines backup file names.", $this->text_domain ) ?></p>
								</td>
							</tr>
							<tr valign="top">
								<th scope="row"><label for="local_folder"><?php _e( 'Local folder path', $this->text_domain ); ?></label></th>
								<td>
									<input id="local_folder" name="local_folder" type="text" <?php __checked_selected_helper( defined( 'BACKUP_LOCAL_FOLDER' ), true, true, 'readonly' ); ?> class="regular-text code" value="<?php echo esc_html( $this->options['local_folder'] ); ?>" />
									<p class="description"><?php _e( "Local backups as well as other files created by the plugin will be stored on this path.", $this->text_domain ) ?></p>
								</td>
							</tr>
							<?php if ( $this->goauth->is_authorized() ) { ?>
							<tr valign="top">
								<th scope="row"><label for="drive_folder"><?php _e( 'Drive folder ID', $this->text_domain ); ?></label></th>
								<td>
									<input id="drive_folder" name="drive_folder" type="text" <?php __checked_selected_helper( defined( 'BACKUP_DRIVE_FOLDER' ), true, true, 'readonly' ); ?> class="regular-text code" value="<?php echo esc_html( $this->options['drive_folder'] ); ?>" />
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
								<th scope="row"><?php _e( "What to back up", $this->text_domain ); ?></th>
								<td>
									<div class="feature-filter">
										<ol class="feature-group">
										<?php foreach ( $this->sources as $name => $source )
											echo '<li><label for="source_' . $name . '" title="' . $source['path'] . '"><input id="source_' . $name . '" name="sources[]" type="checkbox" value="' . $name . '" ' . checked( true, in_array( $name, $this->options['source_list'] ), false ) . ' /> ' . $source['title'] . '</label></li>';
										?></ol>
										<div class="clear"></div>
									</div>
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
									<span id="start_wrap" class="hide-if-js">
										<label for="start_day"> <?php _e( "starting on", $this->text_domain ); ?> </label>
										<select id="start_day" name="start_day">
											<?php
											for ($day_index = 0; $day_index <= 6; $day_index++)
												echo '<option value="' . esc_attr($day_index) . '">' . $wp_locale->get_weekday($day_index) . '</option>';
											?>
										</select>
										<label for="start_hour"> <?php _e( "at", $this->text_domain ); ?> </label>
										<input id="start_hour" name="start_hour" type="number" min="0" max="23" step="1" class="small-text code" value="0" /><label for="start_minute">:</label><input id="start_minute" name="start_minute" type="number" min="0" max="59" step="1" class="small-text code" value="0" />
									</span>
									<div class="description"><?php printf( __( "Select %s if you want to add a cron job to do backups.", $this->text_domain ), "<kbd>" . __( "never", $this->text_domain ) . "</kbd>" ); ?></div>
								</td>
							</tr>
						</tbody>
					</table>
					<?php do_meta_boxes( $this->pagehook, 'normal', '' ); ?>
					<p class="submit">
						<input name="submit" type="submit" class="button-primary" value="<?php _e( 'Save changes', $this->text_domain ) ?>" />
					</p>
				</div>
			</form>
			<div id="postbox-container-1" class="postbox-container">
				<?php do_meta_boxes( $this->pagehook, 'side', '' ); ?>
			</div>
			<div id="postbox-container-2" class="postbox-container">
				<?php do_meta_boxes( $this->pagehook, 'advanced', '' ); ?>
			</div>
			<br class="clear"/>
		</div>
	</div>
</div>