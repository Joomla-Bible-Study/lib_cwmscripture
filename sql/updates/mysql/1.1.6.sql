--
-- Table structure for table `#__bsms_scripture_consumers`
--
-- Registry of extensions that depend on this library. Any extension using the
-- CWM\Library\Scripture classes should register itself here from its install
-- script (see ConsumerRegistry) so that uninstalling the library cannot pull it
-- out from under them, and so the shared bible tables are never dropped while
-- something still reads them. First-party extensions (com_proclaim,
-- plg_content_scripturelinks, plg_task_cwmscripture) are recognised without
-- registering; everyone else must register to be visible.
--

CREATE TABLE IF NOT EXISTS `#__bsms_scripture_consumers` (
    `id`         INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `element`    VARCHAR(100)     NOT NULL COMMENT 'Extension element, e.g. com_foo or the plugin element',
    `type`       VARCHAR(20)      NOT NULL DEFAULT 'component' COMMENT 'Joomla extension type: component, plugin, module, library',
    `folder`     VARCHAR(100)     NOT NULL DEFAULT '' COMMENT 'Plugin group, empty for non-plugins',
    `name`       VARCHAR(255)     NOT NULL DEFAULT '' COMMENT 'Human-readable name shown when an uninstall is refused',
    `registered` DATETIME         NULL DEFAULT NULL COMMENT 'When the consumer registered',
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_consumer` (`element`, `type`, `folder`)
) ENGINE InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;
