=== Offload Media - Cloud Storage  ===
Author: Acowebs
Author URI: https://acowebs.com
Contributors: acowebs, salmanharis
Donate link: https://acowebs.com
Tags: media, offload, cloud, CDN, performance
License: GPLv2 or later
Requires at least: 5.6
Requires PHP: 7.2
Tested up to: 7.0
Stable tag: 1.8.3

Offload Media moves your WordPress files to cloud storage (AWS S3, DigitalOcean, Cloudflare R2, Google Cloud) to improve site performance.

== Description ==

Offload- Media Cloud Storage is an innovative plugin for WordPress. It will dramatically transform how you interact with media in WordPress and increase the website's performance. Media Cloud Storage is a way to transfer your files, media, and images from a WordPress server to online cloud storage, such as Amazon S3, DigitalOcean Spaces, Cloudflare R2, and Google Cloud Storage Services. And it rewrites the URL to serve files from the same storage provider or another CDN provider.

== HOW TO MANAGE SETTINGS? ==

To manage settings in the Offload Media Cloud Storage, download and activate the WordPress plugin.
Go to the Offload media menu in the left menu bar of WordPress, where you can manage the settings. The settings include configure and Settings.
Configure settings allow you to set up the basic plugin settings, while Settings help for the customization of the media upload and serving strategies.


== <Offload Media - Cloud Storage> Basic Features ==
It improves your website speed. Your website will load more efficiently if the cloud media is being transferred to cloud servers.  It can make your website faster because it reduces server requests.
The plugin you have installed no longer needs to serve the media contents like images, videos, pdfs, ZIPs, PDFs as well as other files that you uploaded to the server.

Here are the features of the Offload Media - Cloud Storage:

🔹 Sync your media to Amazon s3, Google Cloud Storage, Cloudflare R2 or Digital Ocean Spaces.
🔹 Delete files from the server once it is uploaded to the server.
🔹 Customizable base path of server storage.
🔹 Customizable URL structure.
🔹 Object versioning can be created for avoiding invalidations of media.
🔹 Custom CDN can be used for media URLs.
🔹 Pre-signed URLs can be created.
🔹 Woocommerce Support
🔹 Advanced Custom Fields Support
🔹 RTL Support
🔹 Fully compatible with all Acowebs plugins
🔹 WPML string Translations support

== Offload Media - Cloud Storage Pro Features ==

Upgrade to the Pro version of Offload Media - Cloud Storage to unlock advanced automation, storage providers, bulk actions, and deep media optimizations:

🔹 **Expanded Cloud Provider Support:**
* Native integration for Wasabi and MinIO.
* Support for custom S3-compatible cloud storage services.
* Support for Google Cloud Storage Uniform Bucket-Level Access.

🔹 **Media Library Status & Manual Actions:**
* **Offload Status Column:** View offload statuses (Stored on Server, Offloaded, Deleted from Server) directly in your Media Library list with sorting support.
* **Inline Actions:** Manual actions on the attachment details panel to Sync to Cloud, Remove from Cloud, Delete from Server, or Download back to Server.

🔹 **Automated Bulk Operations:**
* Bulk offload all existing WordPress media to your cloud bucket.
* Bulk delete local copies of offloaded media files to instantly reclaim server disk space.
* Bulk download media assets from the cloud back to your web server.

🔹 **Image & WebP Optimization:**
* **Image Compression:** Compress images (lossy or lossless).
* **WebP Generation & Compression:** Automatically generate WebP versions of images, compress them, and offload them to the cloud.
* **WebP Cloud Sync:** Rewrite URLs to serve WebP files automatically when supported.
* **Original Backup Management:** Back up original images during compression, with bulk offloading, deletion, and recovery actions.

🔹 **Advanced Integrations:**
* **WooCommerce Support:** Seamless handling and offloading of WooCommerce product files and digital downloads.
* **Advanced Custom Fields (ACF):** Offloads media referenced within ACF custom fields.
* **WPML Translation Support:** Full compatibility with multilingual WordPress setups and translation workflows.

🔹 **Maintenance & Troubleshooting:**
* **Import Previous Data:** Easily migrate settings and media metadata from older versions.
* **Cache Management:** Clear plugin cache and transients related to cloud assets.
* **Data Cleanup:** Complete option to reset and purge stored data, cached paths, database records, and offloaded media metadata.

== Why Acowebs? ==
Acowebs has established the foundation by providing the experience and expertise to activate WooCommerce stores using compact and highly-performing WooCommerce plugins as well as Shopify applications. We develop top-quality themes and WordPress in the cloud to provide comprehensive solutions that are brimming with unparalleled UX features. Being an award-winning software company, and a part of Acodez, the globally recognized and multiple-award-winning digital agency, our team and support features assure you the best.

Check out our other WordPress plugins [here](https://profiles.wordpress.org/acowebs/#content-plugins).

== OTHER USEFUL LINKS ==

🔹 [Upgrade to Pro](https://acowebs.com/offload-media-cloud-storage/)
🔹 [Documentation](https://acowebs.com/guideline/plugin-docs-faqs/offload-media/)
🔹 [Find Our Other Plugins](https://acowebs.com/woocommerce-plugins/)
🔹 [Support Ticket](https://wordpress.org/support/plugin/offload-media-cloud-storage/#new-topic-0)
🔹 [Facebook](https://www.facebook.com/acowebs)
🔹 [Twitter](https://twitter.com/acowebs)
🔹 [YouTube](https://www.youtube.com/channel/UCXoOjbKiXjDocNMe8qd3WHw)



== INSTALLATION ==

Installation of "Offload Media - Cloud Storage" can be done either by searching for "Offload Media - Cloud Storage" via the "Plugins > Add New" screen in your WordPress dashboard, or by using the following steps:

1. Download the plugin via WordPress.org
1. Upload the ZIP file through the 'Plugins > Add New > Upload' screen in your WordPress dashboard
1. Activate the plugin through the 'Plugins' menu in WordPress

== Screenshots ==

1. Welcome to offload media
2. Choose Cloud Service
3. Service Welcome Page
4. Configure Service Credentials
5. Verifying Service Credentials
6. Save configuration after successful verification
7. Dashboard
8. Settings Page
9. Uploaded Attachment Details

== Frequently Asked Questions ==

= What is WordPress media offloading and how does it improve SEO? =
WordPress media offloading is the process of automatically copying your media files (images, PDFs, videos, and audio) to a secure cloud storage provider and rewriting their URLs so they are served directly from the cloud or a Content Delivery Network (CDN). 

Offloading media dramatically reduces the load on your web server, speeds up page loading times, and saves server disk space. Since Google uses page speed as a ranking factor, faster loading times directly translate to improved search engine optimization (SEO) and a better user experience.

= Which cloud storage providers are supported in the free version? =
The free version of Offload Media - Cloud Storage natively supports:
* Amazon S3 (AWS)
* Google Cloud Storage (GCS)
* Cloudflare R2
* DigitalOcean Spaces

If you need support for Wasabi, MinIO, or custom S3-compatible storage services, you can upgrade to the Pro version.

= Can I serve offloaded media files via a custom CDN? =
Yes! Both the free and Pro versions allow you to configure a Custom CDN / Custom Endpoint URL (such as Cloudflare, Amazon CloudFront, KeyCDN, or Bunny CDN) to serve your offloaded media. This ensures that your assets are delivered from the edge server closest to your site visitors, resulting in lightning-fast response times.

= Does the plugin delete local media library files after uploading them to the cloud? =
Yes, you can enable the "Remove files from server" option in the settings. Once enabled, the plugin will automatically delete the local copy of an image or file from your WordPress server after it has been successfully offloaded to your cloud storage bucket. This is an excellent way to host large media libraries on affordable, small-storage web servers.

= Are private files supported using secure pre-signed URLs? =
Yes! You can enable the "Enable Pre-signed URL" feature. This generates secure, temporary, and expiring URLs for private files or digital assets, protecting your content from unauthorized sharing. You can customize the URL expiration time (in minutes) directly in the settings dashboard.

= Is this plugin compatible with WooCommerce and Advanced Custom Fields (ACF)? =
Yes! Offload Media is built to be fully compatible with WooCommerce, Advanced Custom Fields (ACF), and WPML. It handles rewriting URLs for WooCommerce product pages and ACF custom image/file fields, ensuring that translation workflows and e-commerce transactions run smoothly.

= Why should I upgrade to Offload Media - Cloud Storage Pro? =
The Pro version unlocks advanced optimization and automation capabilities for growing websites:
* **Additional Providers:** Support for Wasabi, MinIO, and custom S3-compatible endpoints.
* **Bulk Tools:** Bulk sync all historical media items, bulk download assets back to the server, and bulk delete local files via a safe background worker.
* **Advanced Media Library UI:** Adds an "Offload Status" column with sorting, plus manual sync, download, and delete actions in the attachment details panel.
* **Image Optimization & WebP:** Compress images, automatically generate and compress WebP versions, sync them to the cloud, and dynamically rewrite URLs to serve WebP files.
* **Google Cloud Uniform Bucket Access:** Dedicated support for uniform access permissions.

== Upgrade Notice ==
= 1.8.3 =
* Fix PHP fatal when rewriting image URLs in the block editor

== Changelog ==
= 1.8.3 =
* Fix PHP fatal when rewriting image URLs in the block editor REST API
= 1.8.2 =
* Fix Cloudflare R2 undefined account ID warning during setup
* Improve Cloudflare R2 credential error messages
* Remove unsupported object ACL usage for Cloudflare R2 uploads
* Improve configure wizard credential verification error display
= 1.8.1 =
* WordPress 7.0 compatibility
* Improvements and bug fixes
= 1.7.0 =
* WordPress 6.9 compatibility
* Improvements and bug fixes
= 1.6.0 =
* WordPress 6.8 compatibility
* Improvements and bug fixes
= 1.5.1 =
* WordPress 6.8 compatibility
* Improvements and bug fixes
= 1.5.0 =
* WordPress 6.8 compatibility
* Improvements and bug fixes
= 1.4.1 =
* WordPress 6.8 compatibility
* Improvements and bug fixes
= 1.4.0 =  
* New configuration UI
* Option to create bucket
* Bug fixes
= 1.3.0 =  
* WordPress 6.7 compatibility
* Added Cloudflare R2 Support
= 1.2.0 =  
* WordPress 6.7 compatibility  
* Bug fixes  
* Updated Amazon S3 and Google Cloud PHP SDKs
= 1.1.11 =
* WP 6.5 support
* Bug Fixes
= 1.1.10 =
* WP 6.4 support
= 1.1.9 =
* Compatibility: Modified/Created some functions for supporting pro version.
= 1.1.8 =
* Compatibility: Modified/Created some functions for supporting pro version.
= 1.1.7 =
* Bug Fix: Offloading count wrong.
* Bug Fix: Digital Ocean Upload Permission Issue.
* Bug Fix: Minor function changes.
= 1.1.6 =
* Tweek: Make sure pro plugin loads last.
= 1.1.5 =
* Tweek: Delete sizes and original file only if uploaded.
* Tweek: Updated method of counting medias.
* Tweek: Minor tweeks to make free version as base plugin.
= 1.1.4 =
* Bug Fix: Multipart upload URL is encoded made decoded and saved to db.
* Bug Fix: Issue in getting error code digital ocean, Amazon s3.
= 1.1.3 =
* Bug Fix: Issue in getting error code digital ocean, Amazon s3
* Bug Fix: Wrong offloaded count
= 1.1.2 =
* Bug Fix: Removed frontend extension checking
* Bug Fix: Issue on deleting attachment
= 1.1.1 =
* Bug Fix: Uploading error for non-image types
* Bug Fix: Uploading issue for original image
= 1.1.0 =
* Tweaks: Performance improvement
* Tweaks: More convinient and performance enhanced cache implementation
* Tweaks: Removed extension check of js and css from frontend 
* Bug Fix: Multiple query executing from frontend
* Bug Fix: Undefined descriptor, value warning
* Bug Fix: Base path empty exception issue
* Bug Fix: Digital Ocean Zip file upload issue
* Compatibility: Wordpress 6.1.x Support
= 1.0.7 =
* Bug FIx: Front end page media subsize URL issue
= 1.0.6 =
* Bug FIx: Minor
= 1.0.5 =
* Bug FIx: Critical Error in post page
* Bug FIx: Critical Error in product page woocommerce
= 1.0.4 =
* Bug FIx: open_basedir is not within the allowed path(s)
* Bug FIx: Remove from server unique filename issue
* Bug FIx: Sizes not uploading to Cloud Services
= 1.0.3 =
* Minor Bug Fixes
= 1.0.2 =
* Tweaks : Readme file updated
* Bug Fixes : Redirecting to configure page on activation of plugin. 
= 1.0.1 =
* Bug Fixes : File not rewriting URL's
= 1.0.0 =
* Initial release
