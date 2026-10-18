# 1.1.2

- Service and route definitions in PHP format instead of XML. Shopware 6.7 no longer shows a deprecation warning, and the plugin stays loadable in 6.8.

# 1.1.1

- Sharper plugin icon (256 px app icon instead of 40 px).

# 1.1.0

- Connection link: opens Cronjob.de with this shop's cronjob already filled in.
- The admin worker is switched off while the cron service calls, and comes back by itself if the calls stop for 15 minutes.
- New tokens are 32 characters long.

# 1.0.0

- First release: secret address that queues due scheduled tasks and consumes the message queue on every call.
