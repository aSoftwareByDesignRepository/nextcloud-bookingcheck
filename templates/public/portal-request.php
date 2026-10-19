<?php
/** Portal sign-in — email → one code or magic link. Nothing else on the page. */
\OCP\Util::addScript('bookingcheck', 'pages/public-portal');
?>
<div class="bck-pub bck-pub--narrow">
	<div class="bck-card">
		<div class="bck-card__body">
			<h1 class="bck-pub__title"><?php p($l->t('Your bookings')); ?></h1>
			<p class="bck-pub__lead"><?php p($l->t('Enter the email you booked with — we send you a sign-in link.')); ?></p>

			<form id="bck-portal-request" class="bck-form" novalidate>
				<div class="bck-field">
					<label for="bck-portal-email" class="bck-label"><?php p($l->t('Your email')); ?></label>
					<input id="bck-portal-email" name="email" type="email" autocomplete="email" required maxlength="190">
					<p class="bck-field-error" id="bck-portal-error" hidden></p>
				</div>
				<div class="bck-form-actions">
					<button type="submit" class="bck-btn bck-btn--primary"><?php p($l->t('Email me a sign-in link')); ?></button>
				</div>
			</form>

			<form id="bck-portal-verify" class="bck-form" hidden novalidate>
				<p class="bck-callout bck-callout--info"><?php p($l->t('Check your inbox — enter the code from the email.')); ?></p>
				<div class="bck-field">
					<label for="bck-portal-code" class="bck-label"><?php p($l->t('Code')); ?></label>
					<input id="bck-portal-code" name="code" type="text" inputmode="numeric"
						autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required>
				</div>
				<div class="bck-form-actions">
					<button type="submit" class="bck-btn bck-btn--primary"><?php p($l->t('Sign in')); ?></button>
				</div>
			</form>
		</div>
	</div>
</div>
