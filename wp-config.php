<?php
/**
 * The base configuration for WordPress
 *
 * The wp-config.php creation script uses this file during the installation.
 * You don't have to use the web site, you can copy this file to "wp-config.php"
 * and fill in the values.
 *
 * This file contains the following configurations:
 *
 * * Database settings
 * * Secret keys
 * * Database table prefix
 * * ABSPATH
 *
 * @link https://wordpress.org/documentation/article/editing-wp-config-php/
 *
 * @package WordPress
 */

// ** Database settings - You can get this info from your web host ** //
/** The name of the database for WordPress */
define( 'DB_NAME', 'seo' );

/** Database username */
define( 'DB_USER', 'root' );

/** Database password */
define( 'DB_PASSWORD', '' );

/** Database hostname */
define( 'DB_HOST', 'localhost' );

/** Database charset to use in creating database tables. */
define( 'DB_CHARSET', 'utf8mb4' );

/** The database collate type. Don't change this if in doubt. */
define( 'DB_COLLATE', '' );

/**#@+
 * Authentication unique keys and salts.
 *
 * Change these to different unique phrases! You can generate these using
 * the {@link https://api.wordpress.org/secret-key/1.1/salt/ WordPress.org secret-key service}.
 *
 * You can change these at any point in time to invalidate all existing cookies.
 * This will force all users to have to log in again.
 *
 * @since 2.6.0
 */
define( 'AUTH_KEY',         'TJL ]i-qEl?]%niiwn&bfi*(oaO0:-<U.$n`hm^)(~ D<iAmUVZph2iBxPQC1oVu' );
define( 'SECURE_AUTH_KEY',  'n|m9 wIpyUA[gH#zdG;@SQ->`*p|y;eIRjNfpG<vB]o{Ig%^;*Wia^#-j+;5SzA9' );
define( 'LOGGED_IN_KEY',    '&)%:ms;vKT&SKv`;ZC8v4ChsFPY3W]Vw,bdR63#u.uGGZ$:v/yOZ;!Tg>XJ.{x[3' );
define( 'NONCE_KEY',        ';b=#d+6 v|4lD07bW#va Iv_hHw( 1Iw-RV.=:Il**d}) ;LpJ)XJ`WDE{=jGU d' );
define( 'AUTH_SALT',        'x._q0T-{r?&Wku2S^Y1Gf5(*5Zm]2t=e)PB7yi*%L!8CcSX<A7drcn?Rn!f`zV31' );
define( 'SECURE_AUTH_SALT', '9sn;5!7yZDHJRl2(b{Av_7bGNr#srrf1~U<4-I-x9J1_|}iW1/m:7Yh:E|]%o5}5' );
define( 'LOGGED_IN_SALT',   '385^.pU4g+L:~j3@K?*pD/0`OhWggU!=.C@2.PWzQlQRpa73k9vycu}s2 11d8Q?' );
define( 'NONCE_SALT',       'cUFF^?0%AKNYZ}[wrXfdKgoyin:k>|MZb}eP4<iilGu$JF#,y(cJw+4W<4ayvXMZ' );

/**#@-*/

/**
 * WordPress database table prefix.
 *
 * You can have multiple installations in one database if you give each
 * a unique prefix. Only numbers, letters, and underscores please!
 */
$table_prefix = 'wp_';

/**
 * For developers: WordPress debugging mode.
 *
 * Change this to true to enable the display of notices during development.
 * It is strongly recommended that plugin and theme developers use WP_DEBUG
 * in their development environments.
 *
 * For information on other constants that can be used for debugging,
 * visit the documentation.
 *
 * @link https://wordpress.org/documentation/article/debugging-in-wordpress/
 */
define( 'WP_DEBUG', false );

/* Add any custom values between this line and the "stop editing" line. */



/* That's all, stop editing! Happy publishing. */

/** Absolute path to the WordPress directory. */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

/** Sets up WordPress vars and included files. */
require_once ABSPATH . 'wp-settings.php';
