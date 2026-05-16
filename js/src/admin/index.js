import app from 'flarum/admin/app';

app.initializers.add('ekumanov/rich-embeds-display', () => {
    const reg = app.registry.for('ekumanov-rich-embeds-display');

    reg.registerSetting({
        setting: 'ekumanov-rich-embeds.ttl_seconds',
        type: 'number',
        label: app.translator.trans('ekumanov-rich-embeds-display.admin.settings.ttl_seconds'),
        help: app.translator.trans('ekumanov-rich-embeds-display.admin.settings.ttl_seconds_help'),
        min: 60,
        default: 2592000, // 30 days
    });

    reg.registerSetting({
        setting: 'ekumanov-rich-embeds.user_rate_per_hour',
        type: 'number',
        label: app.translator.trans('ekumanov-rich-embeds-display.admin.settings.user_rate_per_hour'),
        help: app.translator.trans('ekumanov-rich-embeds-display.admin.settings.user_rate_per_hour_help'),
        min: 0,
        default: 20,
    });

    reg.registerSetting({
        setting: 'ekumanov-rich-embeds.max_urls_per_post',
        type: 'number',
        label: app.translator.trans('ekumanov-rich-embeds-display.admin.settings.max_urls_per_post'),
        help: app.translator.trans('ekumanov-rich-embeds-display.admin.settings.max_urls_per_post_help'),
        min: 0,
        default: 10,
    });

    reg.registerSetting({
        setting: 'ekumanov-rich-embeds.strike_threshold',
        type: 'number',
        label: app.translator.trans('ekumanov-rich-embeds-display.admin.settings.strike_threshold'),
        help: app.translator.trans('ekumanov-rich-embeds-display.admin.settings.strike_threshold_help'),
        min: 1,
        default: 5,
    });

    reg.registerSetting({
        setting: 'ekumanov-rich-embeds.whitelist',
        type: 'textarea',
        label: app.translator.trans('ekumanov-rich-embeds-display.admin.settings.whitelist'),
        help: app.translator.trans('ekumanov-rich-embeds-display.admin.settings.whitelist_help'),
        placeholder: 'example.com\n*.trusted.org',
    });

    reg.registerSetting({
        setting: 'ekumanov-rich-embeds.blacklist',
        type: 'textarea',
        label: app.translator.trans('ekumanov-rich-embeds-display.admin.settings.blacklist'),
        help: app.translator.trans('ekumanov-rich-embeds-display.admin.settings.blacklist_help'),
        placeholder: 'amazon.com\n*.amazon.com\nebay.com',
    });
});
