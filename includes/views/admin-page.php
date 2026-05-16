<?php
/**
 * Admin page template.
 *
 * @package Taxonomy_Term_Migrator
 * @var array<string, string> $taxonomies
 * @var array                 $settings
 * @var array|null            $state
 * @var bool                  $running
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap ttm-wrap">
	<h1><?php esc_html_e( 'Перенос таксономий', 'taxonomy-term-migrator' ); ?></h1>
	<p class="description">
		<?php esc_html_e( 'Перенос терминов и связей товаров между таксономиями WooCommerce. Перед запуском обязательно используйте предпросмотр.', 'taxonomy-term-migrator' ); ?>
	</p>

	<form id="ttm-form" class="ttm-form" method="post" action="">
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="ttm-source"><?php esc_html_e( 'Донорская таксономия', 'taxonomy-term-migrator' ); ?></label>
				</th>
				<td>
					<select id="ttm-source" name="source_taxonomy" <?php disabled( $running ); ?>>
						<option value=""><?php esc_html_e( '— Выберите —', 'taxonomy-term-migrator' ); ?></option>
						<?php foreach ( $taxonomies as $slug => $label ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $settings['source_taxonomy'], $slug ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="ttm-target"><?php esc_html_e( 'Целевая таксономия', 'taxonomy-term-migrator' ); ?></label>
				</th>
				<td>
					<select id="ttm-target" name="target_taxonomy" <?php disabled( $running ); ?>>
						<option value=""><?php esc_html_e( '— Выберите —', 'taxonomy-term-migrator' ); ?></option>
						<?php foreach ( $taxonomies as $slug => $label ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $settings['target_taxonomy'], $slug ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Размер батча', 'taxonomy-term-migrator' ); ?></th>
				<td>
					<select id="ttm-batch-size" name="batch_size" <?php disabled( $running ); ?>>
						<?php foreach ( array( 50, 100, 250, 500 ) as $size ) : ?>
							<option value="<?php echo esc_attr( (string) $size ); ?>" <?php selected( (int) $settings['batch_size'], $size ); ?>>
								<?php echo esc_html( (string) $size ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Товаров за один AJAX-проход.', 'taxonomy-term-migrator' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Режим переноса', 'taxonomy-term-migrator' ); ?></th>
				<td class="ttm-checkboxes">
					<label><input type="checkbox" name="create_missing_terms" value="1" <?php checked( $settings['create_missing_terms'] ); ?> <?php disabled( $running ); ?> /> <?php esc_html_e( 'Создавать отсутствующие термины в целевой таксономии', 'taxonomy-term-migrator' ); ?></label><br />
					<label><input type="checkbox" name="transfer_relations" value="1" <?php checked( $settings['transfer_relations'] ); ?> <?php disabled( $running ); ?> /> <?php esc_html_e( 'Переносить связи товаров', 'taxonomy-term-migrator' ); ?></label><br />
					<label><input type="checkbox" name="remove_old_relations" value="1" <?php checked( $settings['remove_old_relations'] ); ?> <?php disabled( $running ); ?> /> <?php esc_html_e( 'Удалять старые связи после успешного переноса', 'taxonomy-term-migrator' ); ?></label><br />
					<label><input type="checkbox" name="delete_empty_source_terms" id="ttm-delete-terms" value="1" <?php checked( $settings['delete_empty_source_terms'] ); ?> <?php disabled( $running ); ?> /> <?php esc_html_e( 'Удалять пустые термины в донорской таксономии после переноса', 'taxonomy-term-migrator' ); ?></label><br />
					<label class="ttm-confirm-delete" style="<?php echo $settings['delete_empty_source_terms'] ? 'margin-left:1.5em;' : 'display:none;margin-left:1.5em;'; ?>">
						<input type="checkbox" name="confirm_delete_terms" id="ttm-confirm-delete" value="1" <?php checked( $settings['confirm_delete_terms'] ); ?> <?php disabled( $running ); ?> />
						<?php esc_html_e( 'Подтверждаю удаление пустых терминов', 'taxonomy-term-migrator' ); ?>
					</label><br />
					<label><input type="checkbox" name="preserve_slug" value="1" <?php checked( $settings['preserve_slug'] ); ?> <?php disabled( $running ); ?> /> <?php esc_html_e( 'Сохранять slug термина, если он свободен', 'taxonomy-term-migrator' ); ?></label><br />
					<label><input type="checkbox" name="enable_logging" value="1" <?php checked( $settings['enable_logging'] ); ?> <?php disabled( $running ); ?> /> <?php esc_html_e( 'Вести лог операции', 'taxonomy-term-migrator' ); ?></label><br />
					<label><input type="checkbox" name="cleanup_wc_attributes_after" value="1" <?php checked( $settings['cleanup_wc_attributes_after'] ); ?> <?php disabled( $running ); ?> /> <?php esc_html_e( 'После переноса: очистить пустые значения атрибутов, пустые глобальные атрибуты и пустые атрибуты на товарах', 'taxonomy-term-migrator' ); ?></label>
				</td>
			</tr>
		</table>

		<p class="submit ttm-actions">
			<button type="button" class="button button-primary" id="ttm-save" <?php disabled( $running ); ?>>
				<?php esc_html_e( 'Сохранить настройки', 'taxonomy-term-migrator' ); ?>
			</button>
			<button type="button" class="button" id="ttm-preview" <?php disabled( $running ); ?>>
				<?php esc_html_e( 'Предпросмотр', 'taxonomy-term-migrator' ); ?>
			</button>
			<button type="button" class="button" id="ttm-start" <?php disabled( $running ); ?>>
				<?php esc_html_e( 'Запустить перенос', 'taxonomy-term-migrator' ); ?>
			</button>
			<button type="button" class="button button-secondary" id="ttm-stop" <?php disabled( ! $running ); ?>>
				<?php esc_html_e( 'Остановить процесс', 'taxonomy-term-migrator' ); ?>
			</button>
		</p>
		<p class="description ttm-save-hint">
			<?php esc_html_e( '«Сохранить настройки» запоминает выбранные таксономии и параметры для следующего визита. Предпросмотр и перенос используют текущие значения формы.', 'taxonomy-term-migrator' ); ?>
		</p>
	</form>

	<div id="ttm-notice" class="ttm-notice" style="display:none;" role="alert"></div>

	<div id="ttm-progress" class="ttm-progress" style="display:none;">
		<h2><?php esc_html_e( 'Прогресс', 'taxonomy-term-migrator' ); ?></h2>
		<div class="ttm-progress-bar"><div class="ttm-progress-fill" style="width:0%;"></div></div>
		<p class="ttm-progress-text">0 / 0</p>
	</div>

	<div id="ttm-preview-panel" class="ttm-panel" style="display:none;">
		<h2><?php esc_html_e( 'Предпросмотр', 'taxonomy-term-migrator' ); ?></h2>
		<div id="ttm-preview-summary" class="ttm-summary"></div>
		<div class="ttm-table-wrap">
			<table class="widefat striped ttm-preview-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Донорский термин', 'taxonomy-term-migrator' ); ?></th>
						<th><?php esc_html_e( 'Slug', 'taxonomy-term-migrator' ); ?></th>
						<th><?php esc_html_e( 'Целевой термин', 'taxonomy-term-migrator' ); ?></th>
						<th><?php esc_html_e( 'Статус', 'taxonomy-term-migrator' ); ?></th>
					</tr>
				</thead>
				<tbody id="ttm-preview-rows"></tbody>
			</table>
		</div>
	</div>

	<div id="ttm-report" class="ttm-panel ttm-report" style="display:none;">
		<h2><?php esc_html_e( 'Отчёт', 'taxonomy-term-migrator' ); ?></h2>
		<div id="ttm-report-content"></div>
	</div>

	<div class="ttm-panel ttm-attribute-cleanup">
		<h2><?php esc_html_e( 'Очистка атрибутов WooCommerce', 'taxonomy-term-migrator' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Очистка глобальных атрибутов (pa_*) и пустых строк на вкладке «Атрибуты» у товаров (например, пустой Color). Перед запуском сделайте резервную копию.', 'taxonomy-term-migrator' ); ?>
		</p>
		<p class="submit ttm-cleanup-actions">
			<button type="button" class="button" id="ttm-delete-empty-terms" <?php disabled( $running ); ?>>
				<?php esc_html_e( 'Удалить пустые значения атрибутов', 'taxonomy-term-migrator' ); ?>
			</button>
			<button type="button" class="button" id="ttm-delete-empty-attributes" <?php disabled( $running ); ?>>
				<?php esc_html_e( 'Удалить атрибуты без значений или если все значения пустые', 'taxonomy-term-migrator' ); ?>
			</button>
			<button type="button" class="button" id="ttm-cleanup-products" <?php disabled( $running ); ?>>
				<?php esc_html_e( 'Удалить пустые атрибуты с товаров', 'taxonomy-term-migrator' ); ?>
			</button>
			<button type="button" class="button button-secondary" id="ttm-full-cleanup" <?php disabled( $running ); ?>>
				<?php esc_html_e( 'Полная очистка (всё сразу)', 'taxonomy-term-migrator' ); ?>
			</button>
		</p>
		<p class="description">
			<?php esc_html_e( 'Пустое значение — термин без товаров (count = 0) или атрибут на товаре без выбранных значений.', 'taxonomy-term-migrator' ); ?>
		</p>

		<div id="ttm-cleanup-progress" class="ttm-cleanup-progress" style="display:none;" aria-live="polite">
			<p class="ttm-cleanup-title"><strong></strong></p>
			<p class="ttm-cleanup-status"></p>
			<div class="ttm-progress-bar ttm-cleanup-progress-bar">
				<div class="ttm-progress-fill ttm-cleanup-progress-fill" style="width:0%;"></div>
			</div>
			<p class="ttm-cleanup-progress-text">0 / 0</p>
		</div>

		<div id="ttm-cleanup-report" class="ttm-cleanup-report" style="display:none;" role="status">
			<h3><?php esc_html_e( 'Очистка завершена', 'taxonomy-term-migrator' ); ?></h3>
			<div id="ttm-cleanup-report-content"></div>
		</div>
	</div>
</div>
