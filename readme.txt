=== Auto Image Alt Text Generator For SEO ===
Contributors: hatrixsolutions
Tags: alt text, image seo, accessibility, ai, wcag
Requires at least: 5.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.1.1
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

= 💎 Free vs Pro =

**Free Plan:**
* 15 AI generations per month
* Perfect for small blogs and personal sites

**Pro Plan ($10/month):**
* 100 AI generations per month
* Ideal for growing businesses and content-heavy sites
* Payment processing: Handled securely via Stripe.com
* Subscriptions: Managed via Stripe's secure payment platform

**Important:** This plugin connects to external APIs to provide AI-powered features. See "External Services" section below for details.

= 🔐 Privacy & Security =

* Images are processed securely via encrypted API
* No images are stored on external servers
* Alt text is saved directly to your WordPress database
* Fully compliant with WordPress coding standards
* Transparent disclosure of all external service usage

= 🌐 External Services (IMPORTANT - Please Read) =

**⚠️ This plugin REQUIRES connection to external services and will NOT work without them.**

An informational notice will be displayed on first activation explaining what data is sent to external services.

**Hatrix Solutions API (hatrixsolutions.com)**
* **Purpose:** Site registration, usage tracking, subscription management, and AI generation coordination
* **Data sent:** Site URL, WordPress version, plugin version, admin email (optional), usage statistics
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
* **Purpose:** Secure payment processing for Pro plan subscriptions
* **Data sent:** Billing information, email, payment details (handled directly by Stripe)
* **When:** Only if you choose to upgrade to Pro plan
* **Privacy Policy:** https://stripe.com/privacy
* **Why necessary:** Enables secure subscription payments for Pro features

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

The free plan includes 15 AI generations per month. Pro plan ($10/month) includes 100 generations per month. This resets monthly based on your signup date.

= Will this slow down my site? =

No. Alt text generation happens on-demand via API, not during page loads. Once generated, alt text is stored in your WordPress database just like manually-entered alt text.

= Can I edit the generated alt text? =

Absolutely! Click the **Edit** button next to any image to modify the alt text in WordPress's native media editor.

= What happens if I run out of generations? =

You can upgrade to Pro anytime for more monthly generations. Or wait until your limit resets next month.

= Does this work with WooCommerce, ACF, or other plugins? =

Yes! This plugin works with standard WordPress images. Once alt text is generated, it's available everywhere your images are used.

= Is my data secure? =

Yes. Images are processed via secure HTTPS API. No images are stored externally. Alt text is saved directly to your WordPress database.

= Can I bulk generate for all images at once? =

Pro users can use the bulk generation feature. Free users can generate images individually or upgrade to Pro.

= What image formats are supported? =

All standard WordPress image formats: JPG, PNG, GIF, WebP.

= Do I need an API key? =

No! The plugin works out of the box. Your site automatically connects to our secure API on activation.

= What data do you collect? =

We collect: site URL, WordPress version, plugin version, admin email (optional), image URLs (for generation), and usage statistics. This data is necessary to provide the AI generation service, track your monthly limit, and provide support. Full details are disclosed in the readme and in an informational notice.

= Can I use this plugin offline? =

No. This plugin requires connection to external APIs (Hatrix Solutions and OpenAI) to provide AI-powered alt text generation. It cannot function without internet connectivity and API access.

= How is my privacy protected? =

All data is transmitted over secure HTTPS connections. Images are analyzed but not permanently stored on our servers. You can review our Privacy Policy at https://hatrixsolutions.com/privacy

== Screenshots ==

1. **Alt Text Viewer Dashboard** - View all images with their alt text status in grid or table view
2. **Stats Overview** - See total images, missing alt tags, and generation limits at a glance
3. **Bulk Generation** - Process multiple images with one click (Pro feature)
4. **Individual Image Controls** - Generate, edit, or clear alt text for any image
5. **Filter & Search** - Quickly find images missing alt text
6. **Settings Panel** - Simple configuration and account management

== Changelog ==

= 1.1.1 - 2025-01-24 =
* **IMPORTANT:** Added informational notice about external service usage (WordPress.org compliance)
* Added: Dismissible notice explaining data collection on first activation
* Added: Link to full external services disclosure in readme
* Enhanced: Readme with comprehensive external service disclosures
* Enhanced: Privacy and security information section
* Enhanced: FAQ section with data collection and privacy questions
* Improved: Clear explanation of what data is collected and why
* Improved: Links to Privacy Policy and Terms of Service
* Fixed: Site registration now checks URL first to prevent duplicate records
* Fixed: ID gap issue in database when site_id changes locally

= 1.1.0 - 2025-01-19 =
* Enhanced: Plugin lifecycle tracking (activation, deactivation, reactivation, uninstall)
* Enhanced: Automatic site registration with central server
* Enhanced: Site ID preservation across reinstalls
* Enhanced: Developer analytics dashboard with comprehensive metrics
* Enhanced: Pro upgrade tracking via Stripe integration
* Enhanced: Event history tracking with version information
* Improved: Button styling and icon alignment
* Improved: Custom SVG icons for better cross-site compatibility
* Improved: UTC timezone consistency across all tracking
* Fixed: Site ID regeneration on reactivation
* Fixed: Plugin versioning in event logs
* Added: Rate limiting for AJAX endpoints (60 requests/minute)
* Added: Weekly heartbeat for site status updates
* Added: Welcome notice after activation with quick start guide
* Added: Feedback request after 10 generations
* Added: Low credits warning for free users (triggers at ≤3 remaining)
* Added: Dismissible admin notices with persistent tracking
* Added: Monthly reset for low credits notice (reminds each month)

= 1.0.0 - 2024-12-01 =
* Initial public release
* Bulk alt text generation
* Individual image generation
* Alt text viewer with grid and table views
* Search and filter functionality
* Free plan: 15 generations/month
* Pro plan: 100 generations/month
* WordPress 6.8 compatibility
* WCAG accessibility compliance
* Full WordPress coding standards compliance

== Upgrade Notice ==

= 1.1.1 =
**IMPORTANT:** This version adds comprehensive external service disclosure for WordPress.org compliance. You'll see an informational notice explaining what data is sent to external APIs. This is a non-blocking notice that can be dismissed. Also fixes database ID gap issue.

= 1.1.0 =
Major update with enhanced tracking, developer analytics, and improved cross-site compatibility. Upgrade recommended for better performance and reliability.

== Additional Information ==

= Support =
For support, feature requests, or bug reports, please visit [hatrixsolutions.com/support](https://hatrixsolutions.com/support)

= Credits =
Developed by [Hatrix Solutions](https://hatrixsolutions.com)

= Privacy Policy =
Read our privacy policy at [hatrixsolutions.com/privacy](https://hatrixsolutions.com/privacy)

