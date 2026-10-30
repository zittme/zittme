(function($) {
	$(function() {

		// Prevent click on disabled buttons
		$(document).on('click', '.x_btn-disabled', function(e) {
			e.preventDefault();
		});

		// Install & update buttons
		$('.autoinstall_install_btn, .autoinstall_update_btn').on('click', async function(e) {
			e.preventDefault();
			const btn = $(this);
			if (btn.hasClass('x_btn-disabled')) {
				return;
			}

			// 유료(라이선스) 자료: 검증을 위해 도메인·버전을 zitt.me 로 보낸다는 사실을 알리고 동의를 받는다
			const licenseConfirm = btn.data('licenseConfirm');
			if (licenseConfirm && !confirm(licenseConfirm)) {
				return;
			}

			// Disable button while processing
			btn.addClass('x_btn-disabled').removeClass('x_btn-primary');
			btn.data('originalText', btn.text());

			// Step 0: 유료 자료는 설치 직전에 라이선스를 다시 검증하고 결과를 화면에 남긴다
			if (licenseConfirm) {
				btn.text(btn.data('verifying'));
				const result = $('#zmst_license_result');
				result.removeClass('is-ok is-fail').text(btn.data('verifying')).show();
				const res0 = await Zittme.ajax('autoinstall.procAutoinstallAdminVerifyLicense', {
					'package_srl': parseInt(btn.data('packageSrl'), 10)
				}).catch(function(err) {
					result.addClass('is-fail').text(err.message);
					NativeAlert(err.message);
					btn.removeClass('x_btn-disabled').addClass('x_btn-primary');
					btn.text(btn.data('originalText'));
				});
				if (!res0 || res0.message !== 'success' || !res0.licensed) {
					return;
				}
				result.addClass('is-ok').text(
					btn.data('verified') + ' · ' + res0.key_masked + ' · ' + res0.domain
					+ (res0.active_date ? ' · ' + res0.active_date : '')
				);
			}

			// Step 1: download
			btn.text(btn.data('downloading'));
			const res1 = await Zittme.ajax('autoinstall.procAutoinstallAdminDownloadPackage', {
				'package_srl': parseInt(btn.data('packageSrl'), 10),
				'mode': btn.hasClass('autoinstall_install_btn') ? 'install' : 'update'
			}).catch(function(err) {
				NativeAlert(err.message);
				btn.removeClass('x_btn-disabled').addClass('x_btn-primary');
				btn.text(btn.data('originalText'));
			});
			if (!res1 || res1.message !== 'success') {
				return;
			}

			// Step 2: install
			btn.text(btn.data('installing'));
			const res2 = await Zittme.ajax('autoinstall.procAutoinstallAdminInstallPackage', {
				'package_srl': parseInt(btn.data('packageSrl'), 10),
				'mode': btn.hasClass('autoinstall_install_btn') ? 'install' : 'update'
			}).catch(function(err) {
				NativeAlert(err.message);
				btn.removeClass('x_btn-disabled').addClass('x_btn-primary');
				btn.text(btn.data('originalText'));
			});
			if (!res2 || res2.message !== 'success') {
				return;
			}

			// Step 3: post-install cleanup
			btn.text(btn.data('cleanup'));
			Zittme.ajax('autoinstall.procAutoinstallAdminPostInstallPackage', {
				'package_srl': parseInt(btn.data('packageSrl'), 10),
				'mode': btn.hasClass('autoinstall_install_btn') ? 'install' : 'update'
			}).then(function(res3) {
				NativeAlert(btn.data('complete'));
				location.reload();
			}).catch(function(err) {
				NativeAlert(err.message);
				btn.removeClass('x_btn-disabled').addClass('x_btn-primary');
				btn.text(btn.data('originalText'));
			});
		});

		// 업데이트 탭: 업데이트 가능한 자료를 차례로 한 번에 갱신한다
		$('#zmstUpdateAll').on('click', async function(e) {
			e.preventDefault();
			const btn = $(this);
			if (btn.hasClass('x_btn-disabled')) {
				return;
			}
			const items = $('.zmst-item[data-bulk-srl]').filter(function() { return $(this).attr('data-bulk-skip') !== 'Y'; }).toArray();
			if (!items.length || !confirm(btn.data('confirm'))) {
				return;
			}
			if (items.some(function(li) { return $(li).attr('data-bulk-paid') === 'Y'; }) && !confirm(btn.data('licenseConfirm'))) {
				return;
			}
			btn.addClass('x_btn-disabled').removeClass('x_btn-primary');
			const status = $('#zmstBulkStatus');
			const failed = [];
			let ok = 0;
			for (let i = 0; i < items.length; i++) {
				const li = $(items[i]);
				const srl = parseInt(li.attr('data-bulk-srl'), 10);
				const title = li.attr('data-bulk-title');
				const prefix = '(' + (i + 1) + '/' + items.length + ') ' + title + ' - ';
				try {
					if (li.attr('data-bulk-paid') === 'Y') {
						const lic = await Zittme.ajax('autoinstall.procAutoinstallAdminVerifyLicense', { 'package_srl': srl });
						if (!lic || !lic.licensed) {
							throw new Error('license');
						}
					}
					status.text(prefix + btn.data('downloading'));
					await Zittme.ajax('autoinstall.procAutoinstallAdminDownloadPackage', { 'package_srl': srl, 'mode': 'update' });
					status.text(prefix + btn.data('installing'));
					await Zittme.ajax('autoinstall.procAutoinstallAdminInstallPackage', { 'package_srl': srl, 'mode': 'update' });
					status.text(prefix + btn.data('cleanup'));
					await Zittme.ajax('autoinstall.procAutoinstallAdminPostInstallPackage', { 'package_srl': srl, 'mode': 'update' });
					ok++;
				} catch (err) {
					failed.push(title);
				}
			}
			let message = String(btn.data('done')).replace('%d', ok);
			if (failed.length) {
				message += '\n' + String(btn.data('failed')).replace('%s', failed.join(', '));
			}
			NativeAlert(message);
			location.reload();
		});

		// Uninstall button
		$('.autoinstall_uninstall_btn').on('click', function(e) {
			e.preventDefault();
			const btn = $(this);
			if (!confirm(btn.data('confirm'))) {
				return;
			}
			Zittme.ajax('autoinstall.procAutoinstallAdminUninstallPackage', {
				'package_srl': parseInt(btn.data('packageSrl'), 10)
			}).then(function(data) {
				NativeAlert(data.message);
				location.reload();
			}).catch(function(err) {
				NativeAlert(err.message);
			});
		});
	});
})(jQuery);
