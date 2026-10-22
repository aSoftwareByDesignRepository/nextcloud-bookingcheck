<?php

declare(strict_types=1);

/**
 * Route table for BookingCheck.
 *
 * Conventions:
 * - Admin routes are session-authenticated and additionally gated by
 *   AppAccessMiddleware (App Admin role) or door-role checks.
 * - /pub/* routes are anonymous. Every ID/token-bearing public route must
 *   serialize "not found" and "not yours" identically (uniform 404/202).
 * - /psp/* receivers are signature-verified, no CSRF, no session.
 * - /capi/* is the versioned companion-app API (token auth, never cookies).
 */

return [
	'routes' => [
		// ---------------------------------------------------------------
		// Logged-in app (seller/admin UI)
		// ---------------------------------------------------------------
		['name' => 'page#index',        'url' => '/',                              'verb' => 'GET'],
		['name' => 'page#needsRole',    'url' => '/needs-role',                    'verb' => 'GET'],
		['name' => 'page#supportUs',    'url' => '/support-us',                    'verb' => 'GET'],
		['name' => 'page#pro',          'url' => '/pro',                           'verb' => 'GET'],
		['name' => 'page#settings',     'url' => '/settings',                      'verb' => 'GET'],
		['name' => 'page#settingsSection', 'url' => '/settings/{section}',         'verb' => 'GET',
			'requirements' => ['section' => 'access|payments|invoicing|flow|portal|integrations|emails|license']],

		// Overview / dashboard
		['name' => 'dashboard#index',   'url' => '/overview',                      'verb' => 'GET'],
		['name' => 'dashboard#stats',   'url' => '/api/overview/stats',            'verb' => 'GET'],

		// Offerings
		['name' => 'offering#index',    'url' => '/offerings',                     'verb' => 'GET'],
		['name' => 'offering#create',   'url' => '/offerings/new',                 'verb' => 'GET'],
		['name' => 'offering#store',    'url' => '/api/offerings',                 'verb' => 'POST'],
		['name' => 'offering#data',     'url' => '/api/offerings/{id}',            'verb' => 'GET',  'requirements' => ['id' => '\d+']],
		['name' => 'offering#show',     'url' => '/offerings/{id}',                'verb' => 'GET',  'requirements' => ['id' => '\d+']],
		['name' => 'offering#edit',     'url' => '/offerings/{id}/edit',           'verb' => 'GET',  'requirements' => ['id' => '\d+']],
		['name' => 'offering#update',   'url' => '/api/offerings/{id}',            'verb' => 'PUT',  'requirements' => ['id' => '\d+']],
		['name' => 'offering#destroy',  'url' => '/api/offerings/{id}',            'verb' => 'DELETE','requirements' => ['id' => '\d+']],
		['name' => 'offering#transition','url' => '/api/offerings/{id}/transition','verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'offering#duplicate','url' => '/api/offerings/{id}/duplicate',  'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'offering#toggleIntegration','url' => '/api/offerings/{id}/integration','verb' => 'POST', 'requirements' => ['id' => '\d+']],

		// Slots / sessions
		['name' => 'slot#list',         'url' => '/api/offerings/{id}/slots',      'verb' => 'GET',  'requirements' => ['id' => '\d+']],
		['name' => 'slot#store',        'url' => '/api/offerings/{id}/slots',      'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'slot#update',       'url' => '/api/slots/{id}',                'verb' => 'PUT',  'requirements' => ['id' => '\d+']],
		['name' => 'slot#destroy',      'url' => '/api/slots/{id}',                'verb' => 'DELETE','requirements' => ['id' => '\d+']],
		['name' => 'slot#series',       'url' => '/api/offerings/{id}/slot-series','verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'slot#templates',    'url' => '/api/offerings/{id}/slot-templates','verb' => 'GET','requirements' => ['id' => '\d+']],
		['name' => 'slot#templateStore','url' => '/api/offerings/{id}/slot-templates','verb' => 'POST','requirements' => ['id' => '\d+']],
		['name' => 'slot#templateDestroy','url' => '/api/slot-templates/{id}',     'verb' => 'DELETE','requirements' => ['id' => '\d+']],

		// Orders
		['name' => 'order#index',       'url' => '/orders',                        'verb' => 'GET'],
		['name' => 'order#show',        'url' => '/orders/{id}',                   'verb' => 'GET',  'requirements' => ['id' => '\d+']],
		['name' => 'order#cancel',      'url' => '/api/orders/{id}/cancel',        'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'order#refundQuote', 'url' => '/api/orders/{id}/refund-quote',  'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'order#refund',      'url' => '/api/orders/{id}/refund',        'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'order#resend',      'url' => '/api/orders/{id}/resend',        'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'order#approve',     'url' => '/api/orders/{id}/approve',       'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'order#decline',     'url' => '/api/orders/{id}/decline',       'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'order#addNote',     'url' => '/api/orders/{id}/notes',         'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'order#markPaid',    'url' => '/api/orders/{id}/mark-paid',    'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'order#abuseList',   'url' => '/api/abuse',                    'verb' => 'GET'],
		['name' => 'order#resolveAbuse','url' => '/api/abuse/{id}/resolve',       'verb' => 'POST', 'requirements' => ['id' => '\d+']],

		// Roster & check-in (app)
		['name' => 'roster#index',      'url' => '/roster',                        'verb' => 'GET'],
		['name' => 'roster#roster',     'url' => '/api/targets/{type}/{id}/roster','verb' => 'GET',  'requirements' => ['type' => 'event|slot|series', 'id' => '\d+']],
		['name' => 'roster#checkIn',    'url' => '/api/attendees/{id}/check-in',   'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'roster#undoCheckIn','url' => '/api/attendees/{id}/check-in',   'verb' => 'DELETE','requirements' => ['id' => '\d+']],

		// Waitlist
		['name' => 'waitlist#index',    'url' => '/waitlist',                      'verb' => 'GET'],
		['name' => 'waitlist#remove',   'url' => '/api/waitlist/{id}',             'verb' => 'DELETE','requirements' => ['id' => '\d+']],

		// Vouchers / promos / add-ons / price categories / resources
		['name' => 'voucher#index',     'url' => '/vouchers',                      'verb' => 'GET'],
		['name' => 'voucher#store',     'url' => '/api/vouchers',                  'verb' => 'POST'],
		['name' => 'voucher#destroy',   'url' => '/api/vouchers/{id}',             'verb' => 'DELETE','requirements' => ['id' => '\d+']],
		['name' => 'promo#index',       'url' => '/promos',                        'verb' => 'GET'],
		['name' => 'promo#store',       'url' => '/api/promos',                    'verb' => 'POST'],
		['name' => 'promo#destroy',     'url' => '/api/promos/{id}',               'verb' => 'DELETE','requirements' => ['id' => '\d+']],
		['name' => 'addon#index',       'url' => '/api/offerings/{id}/addons',     'verb' => 'GET',  'requirements' => ['id' => '\d+']],
		['name' => 'addon#store',       'url' => '/api/offerings/{id}/addons',     'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'addon#destroy',     'url' => '/api/addons/{id}',               'verb' => 'DELETE','requirements' => ['id' => '\d+']],
		['name' => 'pricecat#index',    'url' => '/api/offerings/{id}/price-categories','verb' => 'GET','requirements' => ['id' => '\d+']],
		['name' => 'pricecat#store',    'url' => '/api/offerings/{id}/price-categories','verb' => 'POST','requirements' => ['id' => '\d+']],
		['name' => 'pricecat#destroy',  'url' => '/api/price-categories/{id}',     'verb' => 'DELETE','requirements' => ['id' => '\d+']],
		['name' => 'resource#index',    'url' => '/resources',                     'verb' => 'GET'],
		['name' => 'resource#store',    'url' => '/api/resources',                 'verb' => 'POST'],
		['name' => 'resource#update',   'url' => '/api/resources/{id}',            'verb' => 'PUT',  'requirements' => ['id' => '\d+']],
		['name' => 'resource#destroy',  'url' => '/api/resources/{id}',            'verb' => 'DELETE','requirements' => ['id' => '\d+']],

		// Intake questions
		['name' => 'intake#index',      'url' => '/api/offerings/{id}/intake',     'verb' => 'GET',  'requirements' => ['id' => '\d+']],
		['name' => 'intake#store',      'url' => '/api/offerings/{id}/intake',     'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'intake#update',     'url' => '/api/intake/{id}',               'verb' => 'PUT',  'requirements' => ['id' => '\d+']],
		['name' => 'intake#destroy',    'url' => '/api/intake/{id}',               'verb' => 'DELETE','requirements' => ['id' => '\d+']],

		// Reviews
		['name' => 'review#index',      'url' => '/reviews',                       'verb' => 'GET'],
		['name' => 'review#moderate',   'url' => '/api/reviews/{id}/moderate',     'verb' => 'POST', 'requirements' => ['id' => '\d+']],

		// Materials (admin upload/list)
		['name' => 'material#index',    'url' => '/api/offerings/{id}/materials',  'verb' => 'GET',  'requirements' => ['id' => '\d+']],
		['name' => 'material#upload',   'url' => '/api/offerings/{id}/materials',  'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'material#destroy',  'url' => '/api/materials/{id}',            'verb' => 'DELETE','requirements' => ['id' => '\d+']],

		// Attendee uploads (moderation)
		['name' => 'upload#index',      'url' => '/api/offerings/{id}/uploads',    'verb' => 'GET',  'requirements' => ['id' => '\d+']],
		['name' => 'upload#file',       'url' => '/api/uploads/{id}/file',         'verb' => 'GET',  'requirements' => ['id' => '\d+']],
		['name' => 'upload#destroy',    'url' => '/api/uploads/{id}',              'verb' => 'DELETE','requirements' => ['id' => '\d+']],

		// Attendee broadcasts
		['name' => 'broadcast#send',    'url' => '/api/offerings/{id}/broadcasts', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'broadcast#index',   'url' => '/api/offerings/{id}/broadcasts', 'verb' => 'GET',  'requirements' => ['id' => '\d+']],

		// Reports / exports
		['name' => 'report#index',      'url' => '/reports',                       'verb' => 'GET'],
		['name' => 'report#export',     'url' => '/api/reports/export',            'verb' => 'POST'],
		['name' => 'report#datev',      'url' => '/api/reports/datev',            'verb' => 'GET'],
		['name' => 'report#exportZip',  'url' => '/api/reports/zip/{offeringId}', 'verb' => 'GET', 'requirements' => ['offeringId' => '\d+']],
		['name' => 'report#downloadStats','url' => '/api/reports/dlstats/{offeringId}', 'verb' => 'GET', 'requirements' => ['offeringId' => '\d+']],

		// Audit viewer (pro)
		['name' => 'audit#index',       'url' => '/audit',                         'verb' => 'GET'],

		// Outbound webhooks config
		['name' => 'webhook#index',     'url' => '/webhooks',                      'verb' => 'GET'],
		['name' => 'webhook#store',     'url' => '/api/webhooks',                  'verb' => 'POST'],
		['name' => 'webhook#update',    'url' => '/api/webhooks/{id}',             'verb' => 'PUT',  'requirements' => ['id' => '\d+']],
		['name' => 'webhook#destroy',   'url' => '/api/webhooks/{id}',             'verb' => 'DELETE','requirements' => ['id' => '\d+']],

		// Settings JSON endpoints
		['name' => 'settings#setNotifyPaid','url' => '/api/settings/notify-paid',  'verb' => 'POST'],
		['name' => 'settings#mailTplList',  'url' => '/api/settings/mailtpl/list',   'verb' => 'GET'],
		['name' => 'settings#mailTplPut',   'url' => '/api/settings/mailtpl',        'verb' => 'PUT'],
		['name' => 'settings#mailTplDelete','url' => '/api/settings/mailtpl/{id}',   'verb' => 'DELETE', 'requirements' => ['id' => '\\d+']],
		['name' => 'settings#get',      'url' => '/api/settings/{section}',        'verb' => 'GET'],
		['name' => 'settings#searchUsers', 'url' => '/api/settings/directory/users', 'verb' => 'GET'],
		['name' => 'settings#devices',  'url' => '/api/settings/devices/list',     'verb' => 'GET'],
		['name' => 'settings#issueDevice','url' => '/api/settings/devices',        'verb' => 'POST'],
		['name' => 'settings#revokeDevice','url' => '/api/settings/devices/{id}',  'verb' => 'DELETE', 'requirements' => ['id' => '\\d+']],
		['name' => 'settings#put',      'url' => '/api/settings/{section}',        'verb' => 'PUT'],

		// ---------------------------------------------------------------
		// Public anonymous surface  (/pub/*)
		// ---------------------------------------------------------------
		['name' => 'pubCatalog#index',   'url' => '/pub',                          'verb' => 'GET'],
		['name' => 'pubCatalog#offering','url' => '/pub/o/{slug}',                 'verb' => 'GET'],
		['name' => 'pubCheckout#start',  'url' => '/pub/o/{slug}/checkout',        'verb' => 'GET'],
		['name' => 'pubCheckout#create', 'url' => '/pub/api/orders',               'verb' => 'POST'],
		['name' => 'pubCheckout#status', 'url' => '/pub/api/orders/{publicRef}',   'verb' => 'GET'],
		['name' => 'pubCheckout#return', 'url' => '/pub/return/{publicRef}',       'verb' => 'GET'],
		['name' => 'pubCheckout#cancel', 'url' => '/pub/api/orders/{publicRef}/cancel','verb' => 'POST'],
		['name' => 'pubCheckout#promo',  'url' => '/pub/api/promo/validate',       'verb' => 'POST'],
		['name' => 'pubCheckout#createCart','url' => '/pub/api/cart',            'verb' => 'POST'],

		// Manage link (bearer token in path, hashed at rest)
		['name' => 'manage#show',        'url' => '/pub/m/{token}',                'verb' => 'GET'],
		['name' => 'manage#cancel',      'url' => '/pub/m/{token}/cancel',         'verb' => 'POST'],
		['name' => 'manage#answers',     'url' => '/pub/m/{token}/answers',        'verb' => 'POST'],
		['name' => 'manage#ics',         'url' => '/pub/m/{token}/ics',            'verb' => 'GET'],
		['name' => 'manage#invoice',     'url' => '/pub/m/{token}/invoice',        'verb' => 'GET'],
		['name' => 'manage#einvoice',    'url' => '/pub/m/{token}/invoice.xml', 'verb' => 'GET'],
		['name' => 'manage#uploadFile',  'url' => '/pub/m/{token}/uploads',        'verb' => 'POST'],
		['name' => 'manage#uploadDownload', 'url' => '/pub/m/{token}/uploads/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'manage#uploadDelete', 'url' => '/pub/m/{token}/uploads/{id}',  'verb' => 'DELETE', 'requirements' => ['id' => '\d+']],
		['name' => 'manage#uploadThumb','url' => '/pub/m/{token}/uploads/{id}/thumb', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'manage#reportAbuse','url' => '/pub/m/{token}/uploads/{id}/report', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'manage#ticket',      'url' => '/pub/m/{token}/tickets/{ticketId}', 'verb' => 'GET','requirements' => ['ticketId' => '\d+']],
		['name' => 'manage#appleWallet', 'url' => '/pub/m/{token}/tickets/{ticketId}/wallet.apple', 'verb' => 'GET','requirements' => ['ticketId' => '\d+']],
		['name' => 'manage#pay',         'url' => '/pub/m/{token}/pay',            'verb' => 'POST'],
		['name' => 'manage#recover',     'url' => '/pub/m/recover',                'verb' => 'POST'],

		// Customer portal (OTP)
		['name' => 'portal#request',     'url' => '/pub/portal',                   'verb' => 'GET'],
		['name' => 'portal#send',        'url' => '/pub/api/portal/send',          'verb' => 'POST'],
		['name' => 'portal#verify',      'url' => '/pub/api/portal/verify',        'verb' => 'POST'],
		['name' => 'portal#magic',       'url' => '/pub/portal/magic/{token}',     'verb' => 'GET'],
		['name' => 'portal#home',        'url' => '/pub/portal/home',              'verb' => 'GET'],
		['name' => 'portal#order',       'url' => '/pub/portal/orders/{id}',       'verb' => 'GET',  'requirements' => ['id' => '\d+']],
		['name' => 'portal#download',    'url' => '/pub/portal/materials/{id}',    'verb' => 'GET',  'requirements' => ['id' => '\d+']],
		['name' => 'portal#preview',     'url' => '/pub/portal/materials/{id}/preview', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'portal#uploadThumb', 'url' => '/pub/portal/uploads/{id}/thumb', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'portal#reportAbuse', 'url' => '/pub/portal/abuse',            'verb' => 'POST'],
		['name' => 'portal#logout',      'url' => '/pub/portal/logout',            'verb' => 'POST'],

		// Waitlist public join
		['name' => 'pubWaitlist#join',   'url' => '/pub/api/waitlist',             'verb' => 'POST'],

		// Claim links (attendee account linking)
		['name' => 'claim#show',         'url' => '/pub/claim/{token}',            'verb' => 'GET'],
		['name' => 'claim#accept',       'url' => '/pub/claim/{token}',            'verb' => 'POST'],

		// Public reviews
		['name' => 'pubReview#store',    'url' => '/pub/api/reviews',              'verb' => 'POST'],

		// Embed (iframe-safe) + NH-05 SDK/WebComponent alternative
		['name' => 'embed#offering',     'url' => '/pub/embed/o/{slug}',           'verb' => 'GET'],
		['name' => 'embed#sdk',          'url' => '/pub/sdk.js',                   'verb' => 'GET'],
		['name' => 'pubCatalog#offeringJson','url' => '/pub/api/o/{slug}',         'verb' => 'GET'],

		// Public directory feed (JSON, no checkout)
		['name' => 'feed#directory',     'url' => '/pub/feed/directory.json',      'verb' => 'GET'],
		['name' => 'ical#seller',        'url' => '/pub/ical/seller/{token}.ics', 'verb' => 'GET'],
		['name' => 'ical#order',         'url' => '/pub/ical/order/{token}.ics',  'verb' => 'GET'],

		// ---------------------------------------------------------------
		// PSP webhook receivers (signature-verified, no session, no CSRF)
		// ---------------------------------------------------------------
		['name' => 'psp#mollie',         'url' => '/psp/mollie',                   'verb' => 'POST'],
		['name' => 'psp#stripe',         'url' => '/psp/stripe',                   'verb' => 'POST'],
		['name' => 'psp#test',           'url' => '/psp/test',                     'verb' => 'POST'],

		// Sandbox checkout surface — only live while psp_provider=test (§9.13)
		['name' => 'pubSandbox#pay',     'url' => '/pub/sandbox/pay/{pspRef}',     'verb' => 'GET'],
		['name' => 'pubSandbox#settle',  'url' => '/pub/sandbox/settle/{pspRef}',  'verb' => 'POST'],

		// ---------------------------------------------------------------
		// Door check-in surface (restricted door session or admin)
		// ---------------------------------------------------------------
		['name' => 'scan#index',         'url' => '/scan',                         'verb' => 'GET'],
		['name' => 'scan#openSession',   'url' => '/api/scan/session',             'verb' => 'POST'],
		['name' => 'scan#closeSession',  'url' => '/api/scan/session-close',       'verb' => 'POST'],
		['name' => 'scan#lookup',        'url' => '/api/scan/lookup',              'verb' => 'POST'],
		['name' => 'scan#checkIn',       'url' => '/api/scan/check-in',            'verb' => 'POST'],

		// ---------------------------------------------------------------
		// Companion API (v1) — token auth via companion middleware
		// ---------------------------------------------------------------
		['name' => 'capi#events',        'url' => '/capi/v1/events',               'verb' => 'GET'],
		['name' => 'capi#roster',        'url' => '/capi/v1/slots/{id}/roster',    'verb' => 'GET',  'requirements' => ['id' => '\d+']],
		['name' => 'capi#targetRoster',  'url' => '/capi/v1/targets/{type}/{id}/roster', 'verb' => 'GET', 'requirements' => ['type' => 'event|slot|series', 'id' => '\d+']],
		['name' => 'capi#checkIn',       'url' => '/capi/v1/check-in',             'verb' => 'POST'],
		['name' => 'capi#undoCheckIn',   'url' => '/capi/v1/check-in',             'verb' => 'DELETE'],
		['name' => 'capi#manifest',      'url' => '/capi/v1/manifest',             'verb' => 'GET'],
		['name' => 'capi#stats',         'url' => '/capi/v1/stats',                'verb' => 'GET'],
		['name' => 'capi#admitPass',     'url' => '/capi/v1/pass',               'verb' => 'POST'],
		['name' => 'capi#walkup',        'url' => '/capi/v1/walkup',             'verb' => 'POST'],
		['name' => 'capi#registerPush',  'url' => '/capi/v1/push',               'verb' => 'POST'],
		['name' => 'capi#createOffering','url' => '/capi/v1/offerings',          'verb' => 'POST'],
		['name' => 'capi#orders',        'url' => '/capi/v1/orders',             'verb' => 'GET'],
		['name' => 'capi#order',         'url' => '/capi/v1/orders/{id}',        'verb' => 'GET',  'requirements' => ['id' => '\d+']],
		['name' => 'capi#orderRefundQuote', 'url' => '/capi/v1/orders/{id}/refund-quote', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'capi#orderRefund',   'url' => '/capi/v1/orders/{id}/refund', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],

		// ---------------------------------------------------------------
		// OCS API (admin, for integrations)
		// ---------------------------------------------------------------
		['name' => 'ocsApi#offerings',   'url' => '/api/v1/offerings',             'verb' => 'GET'],
	],
	'ocs' => [
		['name' => 'ocsApi#offeringsOcs', 'url' => '/api/v1/offerings',            'verb' => 'GET'],
	],
];
