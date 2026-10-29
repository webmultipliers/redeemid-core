# Protected files

A gated article stops the text, but a PDF linked from it has its own address. Protected files close that gap: the file's address checks the visitor's access on every download, so a copied link is no use to anyone who isn't entitled when they open it.

## Protecting a file

Open the file in the media library (the media modal or the attachment's edit screen) and set **Who can download**:

- **Everyone:** a normal public file.
- **Whoever can read the item it's attached to:** the item's whole rule decides, exactly as on the page: site default, post type, terms, the item's own setting, time windows, archive windows and drip. A file that isn't attached to an item grants nobody.
- **Members holding a level:** for example *Members holding Gold*. Levels a member holds through another count (Gold includes Silver).
- **Members with a feature:** for example a `downloads` feature mapped to an add-on tag (see [features.md](features.md)).

Anyone who can edit the file can always download it.

Choosing anything but **Everyone** moves the file, and every image size WordPress made of it, into `wp-content/uploads/redeemid-protected/`. From then on every address WordPress builds for it (file blocks, image blocks, the media library, REST) is `https://example.com/?redeemid_file=<id>`. Choosing **Everyone** again moves it back.

Shopify still decides who is entitled: the check reads the same synced tags, levels and features as the gates. Nothing here grants access on WordPress's own say.

## What a visitor gets

- Entitled: the file. PDFs, images, audio, video and plain text open in the browser; everything else downloads. The response is marked private and never cached.
- Signed out: a "This file is for members. Sign in to download it." page (HTTP 401) with a link to the item.
- Signed in without access: "Your membership doesn't include this file." (HTTP 403) with a link to the item, where the wall explains how to get it.

A direct link saved before the file was protected (`/wp-content/uploads/2026/10/guide.pdf`) now points at a file that isn't there. RedeemID redirects that 404 to the checked address, so old links in posts and emails keep working for members.

## Close the folder on your web server

The check only works if the web server refuses to serve the folder itself. **Site Health** (Tools → Site Health) tests this with a probe file and reports a critical issue if the folder is open. The RedeemID Health block shows the last result.

### Apache and LiteSpeed

Nothing to do. RedeemID writes an `.htaccess` into the folder that denies every request. If Site Health still reports the folder open, the server ignores `.htaccess` files: set `AllowOverride All` (or at least `AuthConfig Limit`) for the site, or put the same rule in the virtual host:

```apache
<Directory "/path/to/wp-content/uploads/redeemid-protected">
	Require all denied
</Directory>
```

### nginx

nginx never reads `.htaccess`. Add this to the site's `server` block, above any other `location` that matches uploads, and reload nginx:

```nginx
location ^~ /wp-content/uploads/redeemid-protected/ {
	internal;
}
```

`internal` returns 404 to any direct request and still lets PHP hand the transfer to nginx (below). On managed hosts that don't let you edit nginx (many WordPress hosts), ask support to add it; quote the block above.

## Faster downloads (optional)

PHP streams the file by default, which is fine for documents. For large files, let the web server send it once RedeemID has checked access:

```php
// nginx, with the internal location above.
add_filter( 'redeemid_file_sendfile', fn() => 'x-accel-redirect' );

// Apache with mod_xsendfile, or LiteSpeed.
add_filter( 'redeemid_file_sendfile', fn() => 'x-sendfile' );
```

## Limits

- Byte ranges aren't supported when PHP streams the file, so seeking far into a long video waits for the download. Use one of the server modes above for media.
- Old direct links redirect for the original file only, not for an image's other sizes.
- Plugins that move uploads to a CDN or object storage (S3 and similar) bypass this folder. Keep protected files on the server.
- Uninstalling RedeemID leaves protected files where they are, still closed by the server rule. Set each one back to **Everyone** first if you want them public.
- The event log records each download and each refusal (RedeemID → App events), with the user and the file.
