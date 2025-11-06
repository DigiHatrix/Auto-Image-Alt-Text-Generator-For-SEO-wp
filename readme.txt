=== Auto Image Alt Text Generator For SEO ===
Contributors: hatrixsolutions
Tags: alt text, image seo, accessibility, ai, wcag
Requires at least: 5.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatically generate AI-powered alt text for WordPress images. Improve SEO, boost accessibility, and save hours with bulk or on-demand generation.

== Description ==

Stop wasting time writing image descriptions one by one.
**Auto Image Alt Text Generator for SEO** uses advanced AI to automatically create alt text for your images — instantly and intelligently.

= 🪄 Key Features =

* **Bulk Scan & Tag** - Automatically generate alt text for all existing images in your Media Library
* **On-Demand Generation** - Generate alt text for individual images with a single click
* **AI-Powered Descriptions** - Creates short, human-like alt text optimized for SEO and accessibility
* **Alt Text Viewer** - View, filter, and edit all your image alt text in one convenient dashboard
* **Manual Control** - Regenerate or clear alt text for any individual image
* **SEO-Friendly Results** - Improve search visibility and meet WCAG accessibility standards
* **Grid & Table Views** - Choose your preferred way to manage your images
* **Smart Search & Filters** - Quickly find images with or without alt text

= 🚀 Perfect For =

* Bloggers, marketers, and agencies improving image SEO
* Web designers enhancing accessibility compliance
* Site owners managing large media libraries
* Anyone tired of manually writing image alt text

= 🔒 Why It's Better =

Unlike static bulk editors, this plugin connects to AI to understand the actual image content — not just filenames.
It produces natural, keyword-aware alt text that improves ranking and readability.

= 🧰 How It Works =

1. Install and activate the plugin
2. Visit **Auto Image Alt Text Generator for SEO** in your WordPress admin menu
3. View your images with their current alt text status
4. Click **Bulk Generate** or generate individual images
5. Sit back — your images are now SEO-optimized automatically!

**Note:** Free users can use bulk generation - it will use your monthly quota. Upgrade to Pro for 50 generations/month, or purchase Generation Packs ($5 for 20) as needed.

= 💎 Free vs Pro =

**Free Plan:**
* 10 AI generations per month
* Perfect for small blogs and personal sites
* Bulk generation available (uses monthly quota)

**Pro Plan ($10/month):**
* 50 AI generations per month
* Ideal for growing businesses and content-heavy sites
* Secure payment processing via Stripe (see External Services section for details)

**Generation Packs ($5 for 20 generations):**
* One-time purchase - no subscription required
* Add 20 generations to your account
* Works alongside your monthly quota
* Generations never expire - use them whenever you need
* Perfect for occasional extra needs without committing to monthly subscription
* Payment processing via Stripe (see External Services section for details)

**Important:** This plugin connects to external APIs to provide AI-powered features. See "External Services" section below for details.

= 🔐 Privacy & Security =

All data is transmitted securely via HTTPS. Images are analyzed but not stored externally. Alt text is saved directly to your WordPress database. See the External Services section below for complete details on data handling and privacy.

= 🌐 External Services (IMPORTANT - Please Read) =

**⚠️ This plugin REQUIRES connection to external services and will NOT work without them.**

An informational notice will be displayed on first activation explaining what data is sent to external services.

**Hatrix Solutions API (hatrixsolutions.com)**
* **Purpose:** Site registration, usage tracking, subscription management, and AI generation coordination
* **Data sent:** Site URL, WordPress version, plugin version, admin email, usage statistics
* **When:** On plugin activation, weekly status updates, and during alt text generation
* **Privacy Policy:** https://hatrixsolutions.com/privacy
* **Terms of Service:** https://hatrixsolutions.com/terms
* **Why necessary:** Required to track your monthly generation limit, manage subscriptions, and provide support

**OpenAI API (via Hatrix Solutions proxy)**
* **Purpose:** AI-powered image analysis and alt text generation
* **Data sent:** Image URLs from your media library
* **When:** Only when you explicitly click "Generate" or "Bulk Generate"
* **Privacy:** No images are permanently stored on external servers
* **Why necessary:** Powers the AI alt text generation feature

**Stripe Payment Processing (stripe.com)**
* **Purpose:** Secure payment processing for Pro plan subscriptions and Generation Pack purchases
* **Data sent:** Billing information, email, payment details (handled directly by Stripe)
* **When:** Only if you choose to upgrade to Pro plan or purchase Generation Packs
* **Privacy Policy:** https://stripe.com/privacy
* **Why necessary:** Enables secure subscription payments for Pro features and one-time purchases for Generation Packs

**By using this plugin, you agree to:**
1. Send the above data to these external services
2. Have your site registered with Hatrix Solutions for usage tracking
3. Allow weekly status updates to maintain compatibility support
4. Share image URLs with OpenAI API for AI processing

All data transmission is encrypted via HTTPS. Full details are provided in an informational notice on first activation.

== Installation ==

= Automatic Installation =

1. Log in to your WordPress admin panel
2. Go to **Plugins** > **Add New**
3. Search for "Auto Image Alt Text Generator for SEO"
4. Click **Install Now** and then **Activate**

= Manual Installation =

1. Download the plugin zip file
2. Log in to your WordPress admin panel
3. Go to **Plugins** > **Add New** > **Upload Plugin**
4. Choose the zip file and click **Install Now**
5. Click **Activate**

= After Activation =

1. **Review the informational notice** - You'll see a notice explaining what external services the plugin uses. Review and dismiss it.
2. Go to **Auto Image Alt Text Generator for SEO** in the WordPress admin menu
3. You'll see all your images with their current alt text status
4. Use **Bulk Generate** to process multiple images or click **Generate** on individual images
5. That's it! Your images now have AI-generated alt text

**Note:** This plugin requires external API connectivity to function. See the External Services section for details.

== Frequently Asked Questions ==

= Is the AI accurate? =

Yes! The AI analyzes the actual image content to generate relevant, descriptive alt text. It understands objects, scenes, people, actions, and context.

= How many images can I process? =

The free plan includes 10 AI generations per month. Pro plan ($10/month) includes 50 generations per month. This resets monthly based on your signup date.

= Will this slow down my site? =

No. Alt text generation happens on-demand via API, not during page loads. Once generated, alt text is stored in your WordPress database just like manually-entered alt text.

= Can I edit the generated alt text? =

Absolutely! Click the **Edit** button next to any image to modify the alt text in WordPress's native media editor.

= What happens if I run out of generations? =

You can upgrade to Pro anytime for more monthly generations ($10/month for 50 generations), purchase a Generation Pack ($5 for 20 generations), or wait until your limit resets next month.

= Does this work with WooCommerce, ACF, or other plugins? =

Yes! This plugin works with standard WordPress images. Once alt text is generated, it's available everywhere your images are used.

= Is my data secure? =

Yes. All data is transmitted over secure HTTPS connections. See the External Services section for complete security details.

= Can I bulk generate for all images at once? =

Yes! Bulk generation is available to all users. Free users can use bulk generation within their monthly quota. Pro users get 50 generations per month. Generation Packs can also be used for bulk operations.

= What image formats are supported? =

All standard WordPress image formats: JPG, PNG, GIF, WebP.

= Do I need an API key? =

No! The plugin works out of the box. Your site automatically connects to our secure API on activation.

= What data do you collect? =

See the External Services section above for complete details on what data is collected, when it's collected, and why it's necessary.

= Can I use this plugin offline? =

No. This plugin requires external API connectivity to function. See the External Services section for details.

= How is my privacy protected? =

All data is transmitted over secure HTTPS connections. Images are analyzed but not permanently stored on external servers. See the External Services section for complete privacy details and links to our Privacy Policy.

== Screenshots ==

1. **Alt Text Viewer Dashboard** - View all images with their alt text status in grid or table view
2. **Stats Overview** - See total images, missing alt tags, and generation limits at a glance
3. **Bulk Generation** - Process multiple images with one click (available to all users)
4. **Individual Image Controls** - Generate, edit, or clear alt text for any image
5. **Filter & Search** - Quickly find images missing alt text
6. **Settings Panel** - Simple configuration and account management

== Changelog ==

= 1.2.0 - 2025-11-04 =
* **MAJOR:** Removed Basic tier - replaced with Generation Packs ($5 for 20 generations)
* **MAJOR:** Bulk generation now available to all users (not just Pro)
* **FEATURE:** Added Generation Packs - one-time purchase of 20 generations for $5
* **FEATURE:** Paid generations track separately from monthly quota and never expire
* **FEATURE:** Generation logic uses monthly quota first, then paid generations
* **FEATURE:** Separate dashboard cards for Free/Pro monthly generations and Paid generations
* **ENHANCED:** Plugin dashboard now shows paid generations available with refresh button
* **ENHANCED:** Stripe webhook improved metadata parsing for generation pack purchases
* **ENHANCED:** Bulk generation confirmation popup with generation usage reminder
* **IMPROVED:** Better handling of quota exhaustion with paid generation fallback
* **IMPROVED:** Price storage now numeric-only (no $ signs in database)
* **IMPROVED:** Lightning bolt SVG icon for bulk generation button
* **FIXED:** Generation counting now properly excludes paid generations from monthly count
* **FIXED:** Generation pack purchases now correctly add to database via webhook
* **FIXED:** Checkout success page correctly displays pack purchase details
* **FIXED:** WordPress coding standards compliance (i18n translator comments, ordered placeholders)
* **FIXED:** Security improvements (nonce verification, input sanitization)
* **FIXED:** Slow query warnings properly suppressed with phpcs:ignore comments
* **CLEANUP:** Removed verbose error logging from production code

= 1.1.1 - 2025-10-24 =
* Added: Dismissible notice explaining data collection on first activation
* Added: Link to full external services disclosure in readme
* Enhanced: Readme with comprehensive external service disclosures
* Improved: Clear explanation of what data is collected and why

= 1.1.0 - 2025-10-19 =
* Enhanced: Automatic site registration with central server
* Enhanced: Site ID preservation across reinstalls
* Improved: Button styling and icon alignment
* Improved: Custom SVG icons for better cross-site compatibility
* Improved: UTC timezone consistency across all tracking
* Fixed: Site ID regeneration on reactivation
* Fixed: Plugin versioning in event logs
* Added: Rate limiting for AJAX endpoints (60 requests/minute)
* Added: Weekly heartbeat for site status updates
* Added: Welcome notice after activation with quick start guide
* Added: Dismissible admin notices with persistent tracking

= 1.0.0 - 2025-10-01 =
* Bulk alt text generation
* Individual image generation
* Alt text viewer with grid and table views
* Search and filter functionality
* WordPress 6.8 compatibility
* WCAG accessibility compliance
* Full WordPress coding standards compliance


== Additional Information ==

= Support =
For support, feature requests, or bug reports, please visit [hatrixsolutions.com/support](https://hatrixsolutions.com/support)

= Credits =
Developed by [Hatrix Solutions](https://hatrixsolutions.com)

= Privacy Policy =
Read our privacy policy at [hatrixsolutions.com/privacy](https://hatrixsolutions.com/privacy)

