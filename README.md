# AdShimmer

**Prevent Cumulative Layout Shift (CLS) from dynamically injected ads — the "set and forget" way.**

A WordPress plugin that automatically detects ad placements and generates server-side containers with reserved space, eliminating the layout shifts that hurt your Core Web Vitals scores.

---

## The Problem

When ad networks inject ads into your pages, they do so **after** the page has loaded using JavaScript. This causes the page content to suddenly shift as ads appear — a poor user experience that Google penalizes through Core Web Vitals scoring.

**The typical publisher experience:**
1. Ad script loads after DOM is ready
2. Ad wrapper is created dynamically (e.g., `<div id="ad-desktop-billboard-0-wrapper">`)
3. Page content shifts down to make room
4. Google measures this as CLS (Cumulative Layout Shift)
5. Your Core Web Vitals score drops
6. SEO rankings suffer

**The traditional fix is tedious:**
- Manually place div containers in your theme
- Write CSS with min-height for each ad slot
- Repeat for every ad placement on every page type
- Update when ad configurations change

---

## The Solution

AdShimmer automates everything:

1. **Scan** — Enable scan mode, browse your site, and the plugin detects where ads are injected
2. **Configure** — Review detected slots, adjust heights if needed
3. **Generate** — Click one button to write server-side code to your child theme
4. **Done** — Space is reserved from the very first paint, zero CLS

The generated code creates containers that exist in the HTML **before any JavaScript runs**, so there's physically nothing to shift.

---

## Quick Start

### Installation

1. Download and extract to `/wp-content/plugins/adshimmer/`
2. Activate the plugin in WordPress admin
3. Ensure you have a **child theme** active (required for code generation)

### Usage

1. Go to **Settings → AdShimmer**
2. Enable **Scan Mode**
3. Open your site in a new tab and browse pages where ads appear (homepage, articles, etc.)
4. Return to the settings page — detected ad slots will appear
5. Click **Configure** on each slot you want to reserve space for
6. Adjust min-heights if needed (defaults based on common ad specs)
7. Click **Generate Code**
8. Update your ad network dashboard to target the new `.asr-ad-slot` classes (one-time setup)

### That's it!

Your pages now render with pre-sized containers. Ads inject into these containers instead of creating new space, resulting in **zero layout shift**.

---

## How It Works

### The Core Insight

The CLS problem exists because:
- Ad wrappers are created **client-side** after page load
- No space exists for them until JavaScript runs
- Content must shift to accommodate the new elements

The solution:
- Create containers **server-side** in the initial HTML
- These containers have `min-height` set via CSS
- Space exists from the very first paint
- Ad scripts inject **inside** the pre-existing containers
- Nothing shifts because space was already reserved

### The Scan & Learn Workflow

```
┌─────────────────┐     ┌──────────────────┐     ┌─────────────────┐
│   Scan Mode     │────▶│  Detect Wrappers │────▶│  Save to Admin  │
│   (Frontend)    │     │  (MutationObserver)    │  (AJAX)         │
└─────────────────┘     └──────────────────┘     └─────────────────┘
                                                         │
                                                         ▼
┌─────────────────┐     ┌──────────────────┐     ┌─────────────────┐
│  Zero CLS!      │◀────│  Write to Theme  │◀────│  Configure &    │
│  (Production)   │     │  (PHP + CSS)     │     │  Generate       │
└─────────────────┘     └──────────────────┘     └─────────────────┘
```

**Phase 1: Detection**
- Admin enables scan mode and visits the site
- JavaScript uses `MutationObserver` to watch for ad wrapper creation
- When wrappers like `ad-desktop-billboard-btf-0-wrapper` appear, the scanner captures:
  - Wrapper ID pattern
  - Device type (desktop/mobile)
  - Slot type (billboard, rectangle, etc.)
  - Rendered dimensions
  - Parent element selector
  - Page URL and type

**Phase 2: Configuration**
- Admin reviews detected slots in the WordPress dashboard
- Each slot shows suggested min-height based on common ad specifications
- Admin can adjust heights and injection positions

**Phase 3: Code Generation**
- Plugin generates PHP file with `the_content` filter hooks
- Plugin generates CSS file with responsive min-height rules
- Files are written to the active child theme
- `functions.php` is updated to include the generated files

**Phase 4: Production**
- Server renders HTML with containers already in place
- CSS ensures space is reserved before any JS runs
- Ad scripts inject into pre-sized containers
- No layout shift occurs

---

## Technical Architecture

### File Structure

```
adshimmer/
├── adshimmer.php                     # Plugin bootstrap, hooks, AJAX handlers
├── uninstall.php                     # Clean removal (preserves theme files)
├── assets/
│   ├── css/
│   │   └── admin.css                 # Admin UI styles
│   └── js/
│       ├── admin.js                  # Admin functionality
│       └── scanner.js                # Frontend MutationObserver scanner
└── includes/
    ├── admin/
    │   └── class-settings-page.php   # Settings UI, slot management
    ├── class-scanner.php             # Scan data processing
    ├── class-code-generator.php      # PHP/CSS generation
    ├── class-theme-writer.php        # File writing to child theme
    └── class-slot-defaults.php       # Default slot heights
```

### Generated Files (in child theme)

**asr-containers.php**
```php
add_filter('the_content', 'asr_inject_ad_containers', 20);

function asr_inject_ad_containers($content) {
    if (!is_singular() || !in_the_loop()) return $content;

    $is_mobile = wp_is_mobile();
    $containers = $is_mobile ? asr_get_mobile_containers() : asr_get_desktop_containers();

    // Inject containers after specified paragraphs
    // ...
}
```

**asr-styles.css**
```css
.asr-ad-slot {
    display: block;
    width: 100%;
    overflow: hidden;
}

@media (min-width: 992px) {
    .asr-desktop-billboard-btf { min-height: 250px; margin: 20px 0; }
    .asr-desktop-hpa-atf { min-height: 600px; margin: 20px 0; }
}

@media (max-width: 991px) {
    .asr-mobile-rectangle-mid { min-height: 250px; margin: 20px 0; }
}
```

### Data Storage

All configuration is stored in WordPress options:

```php
'asr_settings' => [
    'scan_mode' => false,
    'last_scan' => '2024-01-15 10:30:00',
    'detected_slots' => [
        [
            'wrapperId' => 'asr-desktop-billboard-btf-0-wrapper',
            'device' => 'desktop',
            'slotType' => 'billboard-btf',
            'parentSelector' => '.article-content',
            'renderedHeight' => 250,
            'pageUrl' => '/sample-article/',
            'pageType' => 'article',
        ],
        // ...
    ],
    'configured_slots' => [
        'asr-desktop-billboard-btf-0-wrapper' => [
            'cssClass' => 'asr-desktop-billboard-btf',
            'minHeight' => 250,
            'marginTop' => 20,
            'marginBottom' => 20,
            'injectionLocation' => 'after_paragraph',
            'injectionPosition' => 3,
            'enabled' => true,
        ],
        // ...
    ],
]
```

### Scanner JavaScript (MutationObserver)

```javascript
// Watch for dynamically created ad wrappers
const observer = new MutationObserver((mutations) => {
    mutations.forEach((mutation) => {
        mutation.addedNodes.forEach((node) => {
            if (node.id?.includes('asr') && node.id?.includes('wrapper')) {
                // Extract slot data and send to WordPress admin
            }
        });
    });
});

observer.observe(document.body, { childList: true, subtree: true });
```

---

## Slot Reference

Default heights based on common ad specifications:

### Desktop (≥992px)

| Slot | CSS Class | Height |
|------|-----------|--------|
| Billboard BTF | `.asr-desktop-billboard-btf` | 250px |
| Billboard ATF | `.asr-desktop-billboard-atf` | 250px |
| HPA ATF | `.asr-desktop-hpa-atf` | 600px |
| HPA BTF | `.asr-desktop-hpa-btf` | 600px |
| Video Outstream | `.asr-desktop-video-outstream` | 250px |
| In-Content | `.asr-desktop-incontent` | 250px |
| Leaderboard ATF | `.asr-desktop-leaderboard-atf` | 90px |
| Leaderboard BTF | `.asr-desktop-leaderboard-btf` | 90px |
| Rectangle ATF | `.asr-desktop-rectangle-atf` | 250px |
| Rectangle BTF | `.asr-desktop-rectangle-btf` | 250px |

### Mobile (<992px)

| Slot | CSS Class | Height |
|------|-----------|--------|
| Billboard Top | `.asr-mobile-billboard-top` | 250px |
| Rectangle Infinite | `.asr-mobile-rectangle-infinite` | 250px |
| Rectangle Low | `.asr-mobile-rectangle-low` | 250px |
| Rectangle Mid | `.asr-mobile-rectangle-mid` | 250px |
| Rectangle Mid 300x600 | `.asr-mobile-rectangle-mid-300x600` | 600px |
| Video Outstream | `.asr-mobile-video-outstream` | 250px |

---

## Ad Network Dashboard Configuration

After generating code, update your ad network dashboard with the new targeting selectors:

**Before (causes CLS):**
```
Target: .article-content
Injection: append inside
```

**After (zero CLS):**
```
Target: .asr-ad-slot.asr-desktop-billboard-btf
Injection: inject inside
```

The plugin's settings page shows the exact selectors to use for each configured slot.

---

## Requirements

- WordPress 5.0+
- PHP 7.4+
- Active **child theme** (required for code generation)
- Admin access (capability: `manage_options`)

---

## FAQ

### Why do I need a child theme?

The plugin generates PHP and CSS files that need to persist across theme updates. Writing to a child theme ensures your CLS prevention code survives parent theme updates.

### Will this work with any ad network?

Yes! The scanner detects any dynamically injected elements with ID patterns containing common ad wrapper identifiers. You can customize heights for any ad network.

### What if my ads have variable heights?

Use the maximum expected height as the min-height. The container will expand if the ad is taller, but won't shrink below the minimum, preventing downward shifts.

### Does this affect ad viewability or revenue?

No. The ads still render in exactly the same positions — we're just reserving the space ahead of time. Ad viewability tracking and revenue are unaffected.

### What happens if I deactivate the plugin?

The generated files in your child theme remain active, so CLS prevention continues working. To fully remove, delete `asr-containers.php` and `asr-styles.css` from your child theme.

### Can I manually edit the generated files?

You can, but changes will be overwritten if you click "Generate Code" again. For permanent customizations, copy the code to a separate file that you manage manually.

---

## Hooks & Filters (For Developers)

```php
// Modify generated CSS before output
add_filter('asr_generated_css', function($css, $slots) {
    return $css . "\n/* Custom additions */";
}, 10, 2);

// Control injection on specific posts
add_filter('asr_should_inject', function($should_inject, $post_id) {
    // Skip sponsored posts
    if (get_post_meta($post_id, 'sponsored', true)) {
        return false;
    }
    return $should_inject;
}, 10, 2);

// Allow parent theme instead of child theme (not recommended)
add_filter('asr_allow_parent_theme', '__return_true');
```

---

## Troubleshooting

### Slots not being detected

1. Ensure scan mode is enabled (green indicator should appear on frontend)
2. Make sure you're logged in as an admin
3. Wait a few seconds on each page for ads to load
4. Check browser console for JavaScript errors

### Generated code not working

1. Verify child theme is active
2. Check that `asr-containers.php` exists in child theme
3. Ensure `functions.php` includes the require_once statement
4. Clear any caching plugins

### Still seeing CLS

1. Run Lighthouse/PageSpeed Insights to identify the source
2. Verify the min-height matches or exceeds actual ad height
3. Check that your ad network dashboard targets the `.asr-ad-slot` selectors
4. Ensure CSS is loading early (check network waterfall)

---

## Support

For issues, feature requests, or contributions, please visit the [plugin repository](https://github.com/ItsRayRay).

---

## License

GPL-2.0+ — See [LICENSE](http://www.gnu.org/licenses/gpl-2.0.txt)

---

**Built by [ItsRayRay](https://github.com/ItsRayRay) for publishers who want great Core Web Vitals without the manual work.**
