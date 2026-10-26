// End-to-end flow against a FRESH site where the release zip was installed (not the bind-mounted dev copy),
// so deleting the plugin through the UI cannot touch source files.
// Env: R404S_ZIP_CONTAINER_PATH is already copied in; WP is at baseURL; wp-cli runs via `npx wp-env run cli` in ./pkgtest.
const { test, expect } = require( '@playwright/test' );
const { execSync } = require( 'child_process' );
const path = require( 'path' );

const root = path.resolve( __dirname, '../../../..' );
const shots = path.resolve( __dirname, '../../assets' );
const wp = ( args ) =>
	execSync( `npx wp-env run cli wp ${ args }`, { cwd: path.join( root, 'pkgtest' ), encoding: 'utf8' } );

test.describe.configure( { mode: 'serial' } );

test.beforeAll( () => {
	wp( 'plugin install /tmp/redirect-404-sentinel.zip --force --activate' );
	wp( "option update permalink_structure '/%postname%/'" );
	wp( 'post create --post_type=page --post_status=publish --post_title="About Us" --post_name=about-us' );
} );

test( 'activate, use, configure, deactivate, uninstall, clean database', async ( { page, request } ) => {
	// Log in (wp-env default local test account).
	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', 'admin' );
	await page.fill( '#user_pass', 'password' );
	await page.click( '#wp-submit' );
	await expect( page.locator( '#wpadminbar' ) ).toBeVisible();

	// Plugin is active (installed + activated in beforeAll).
	await page.goto( '/wp-admin/plugins.php' );
	await expect( page.locator( '#deactivate-redirect-404-sentinel' ) ).toBeVisible();

	// Produce two 404s.
	for ( let i = 0; i < 2; i++ ) {
		expect( ( await request.get( '/old/about-uss/', { maxRedirects: 0 } ) ).status() ).toBe( 404 );
	}

	// Open the screen: the 404 is listed with the closest page pre-filled.
	await page.goto( '/wp-admin/tools.php?page=r404s' );
	const row = page.locator( 'tr', { hasText: '/old/about-uss' } );
	await expect( row ).toBeVisible();
	await expect( row.locator( 'input[name=target]' ) ).toHaveValue( /about-us/ );
	await page.screenshot( { path: path.join( shots, 'screenshot-1.png' ), fullPage: true } );

	// Main action: create the 301.
	await row.getByRole( 'button', { name: 'Create 301' } ).click();
	await expect( page.locator( '.notice-success' ) ).toContainText( 'Redirect created.' );
	await page.waitForTimeout( 800 ); // Let the admin notice finish its fade-in.
	await page.screenshot( { path: path.join( shots, 'screenshot-2.png' ), fullPage: true } );

	// Verify the result on the front end.
	const res = await request.get( '/old/about-uss/', { maxRedirects: 0 } );
	expect( res.status() ).toBe( 301 );
	expect( res.headers().location ).toContain( '/about-us' );

	// Loop protection through the UI: a -> b is fine, b -> a is rejected.
	await request.get( '/loop-a/' );
	await request.get( '/loop-b/' );
	const submit = async ( source, target ) => {
		await page.goto( '/wp-admin/tools.php?page=r404s' );
		const r = page.locator( 'tr', { hasText: source } );
		await r.locator( 'input[name=target]' ).fill( target );
		await r.getByRole( 'button', { name: 'Create 301' } ).click();
	};
	await submit( '/loop-a', '/loop-b' );
	await expect( page.locator( '.notice-success' ) ).toContainText( 'Redirect created.' );
	await submit( '/loop-b', '/loop-a' );
	await expect( page.locator( '.notice-error' ) ).toContainText( 'loop' );
	// Change a setting and confirm it persisted.
	await page.fill( '#r404s_retention_days', '7' );
	await page.getByRole( 'button', { name: 'Save', exact: true } ).click();
	await expect( page.locator( '#r404s_retention_days' ) ).toHaveValue( '7' );

	// Deactivate, then delete (runs uninstall.php).
	await page.goto( '/wp-admin/plugins.php' );
	await page.click( '#deactivate-redirect-404-sentinel' );
	await page.locator( '#delete-redirect-404-sentinel' ).waitFor( { timeout: 60000 } );
	// The Delete link is wired up by JavaScript. Clicking before the page settles follows the plain link to a
	// "Yes, delete" confirmation screen, which deletes nothing, so wait first and then check for real.
	await page.waitForLoadState( 'networkidle' );
	page.once( 'dialog', ( d ) => d.accept() );
	await page.click( '#delete-redirect-404-sentinel' );
	// WordPress leaves a placeholder row behind after an AJAX delete, so the reliable evidence is the plugin list itself.
	await expect.poll( () => wp( 'plugin list --field=name --skip-plugins --skip-themes' ).split( /\s+/ ).includes( 'redirect-404-sentinel' ), { timeout: 90000, intervals: [ 3000 ] } ).toBe( false );

	// Database is clean.
	expect( wp( 'db query "SHOW TABLES LIKE \'%sentinel%\'"' ).trim() ).toBe( '' );
	expect( wp( "option list --search='r404s*' --format=count" ).trim() ).toBe( '0' );
} );
