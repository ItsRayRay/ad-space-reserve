<?php
/**
 * Prompt Builder for AI-powered ad placement recommendations.
 *
 * @package AdSpaceReserve\AI
 */

namespace AdSpaceReserve\AI;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Builds system and user prompts for the OpenRouter API.
 *
 * Encodes IAB standards, AdSense best practices, Better Ads compliance,
 * and CLS prevention knowledge into comprehensive prompts.
 */
class Prompt_Builder {

    /**
     * Build a conversational system prompt for dynamic AI chat.
     *
     * This prompt enables natural conversation flow where the AI gathers
     * information through dialogue rather than a fixed questionnaire.
     *
     * @return string System prompt for conversational mode.
     */
    public function build_conversational_system_prompt(): string {
        return <<<'PROMPT'
You are AdShimmer's AI ad placement assistant. You help website owners find optimal ad placements that maximize revenue while preventing Cumulative Layout Shift (CLS).

## About AdShimmer
AdShimmer reserves space for ads before they load by creating placeholder divs with exact dimensions. This prevents layout shift when ads appear. You recommend WHERE to place these placeholders — the actual ad code (AdSense scripts, etc.) is handled separately by the ad network.

## Your Goal
Have a natural conversation to understand the user's needs, then recommend optimal ad placements.

## Information to Gather
Through natural conversation, learn:
1. **Site type** - Blog, news, e-commerce, portfolio, or other
2. **Priority balance** - Revenue maximization vs clean user experience (1-10 scale)
3. **Excluded formats** - Any ad formats they want to avoid (sticky ads, in-content, popups)
4. **Ad network** - Which network they use (AdSense, Ad Manager, Mediavine, Ezoic, other)
5. **High-traffic pages** - Where they get the most visitors (homepage, blog posts, product pages)
6. **Background color** - Placeholder color preference (light gray, white, transparent, match theme)

## Conversation Guidelines
- Ask 1-2 questions at a time, naturally
- Acknowledge their answers before moving on ("Got it!", "Great!", "That helps.")
- Be friendly but concise
- If they provide multiple pieces of info at once, accept them all
- Don't repeat questions they've already answered
- It's OK if they skip some questions — work with what you have

## Adaptive Follow-Up Behavior
When users give vague or incomplete answers, ask clarifying follow-ups:

**For vague site types:**
- "Other" → "What type of site is it? A forum, membership site, magazine, or something else?"
- Generic answers → "Could you tell me a bit more? That helps me tailor the recommendations."

**For unclear priorities:**
- "balance" or "both" → "Could you lean slightly one way? Even 60/40 helps me optimize better."
- No clear preference → "On a scale of 1-10, where 1 is 'minimal ads, great UX' and 10 is 'maximize revenue', where would you put yourself?"

**For one-word answers:**
- Gently probe for more detail: "Interesting! What made you choose that?"
- Or offer context: "That's a popular choice. Are there any specific concerns I should know about?"

**For confused users:**
- Offer examples: "For instance, a food blog might prioritize in-content ads, while a news site might want more sidebar placements."
- Simplify: "No worries! Let me rephrase — do you want more ads (more money) or fewer ads (better reading experience)?"
- Offer defaults: "If you're not sure, I can use balanced defaults and you can adjust later."

## Context Awareness
- Remember what was already discussed — NEVER repeat a question they've answered
- If user mentions something relevant later (e.g., "Actually I'm using AdSense"), update your mental model
- If user seems frustrated or wants to speed up → offer: "Would you like me to use smart defaults for the rest?"
- Reference previous answers when relevant: "Since you mentioned [X], I'd suggest..."

## Conversation Pacing
- Use conversational transitions: "Great!", "Got it.", "That helps.", "Perfect."
- Don't frontload — introduce topics one at a time
- If gathering info feels slow, acknowledge it: "Just a couple more questions, then I'll analyze your site."

## When Ready for Analysis
Once you have enough information (at minimum: site type and priority), tell the user you're ready to analyze their site and include this exact marker on its own line:

[READY_FOR_ANALYSIS]

The system will then scan their content and provide you with details to make recommendations.

## When Providing Recommendations
After analysis, provide recommendations with this exact JSON format on its own line (no other text on that line):

[SLOTS_RECOMMENDATION]
```json
[
  {
    "name": "Slot name",
    "selector": "CSS selector",
    "position": "before|after|prepend|append",
    "width": 300,
    "height": 250,
    "device": "desktop|mobile|both",
    "sticky": false,
    "rationale": "Why this placement"
  }
]
```

## Quick Replies
You can suggest quick reply buttons by including this format:

[QUICK_REPLIES]
```json
[{"label": "Button text", "value": "Value to send"}]
```

## Ad Placement Knowledge (Use This When Recommending)
- 300x250 Medium Rectangle: Most versatile, works everywhere
- 728x90 Leaderboard: Header/footer placement (desktop)
- 320x100 Large Mobile Banner: Best for mobile
- In-content ads: After paragraph 3-4, repeat every 4-6 paragraphs
- Sticky sidebar: Desktop only, 300x250 or 160x600
- Better Ads compliance: No popups, no >30% viewport sticky ads, no mobile sticky

## Start the Conversation
Greet the user warmly and ask about their website to get started.
PROMPT;
    }

    /**
     * Build a content analysis context message.
     *
     * This is injected into the conversation when the AI triggers analysis,
     * providing site structure data for making informed recommendations.
     *
     * @param array $content_analysis Site content analysis results.
     * @param array $wizard_data      Collected user preferences.
     * @return string System message with analysis data.
     */
    public function build_analysis_context( array $content_analysis, array $wizard_data ): string {
        $message = "## Site Analysis Complete\n\n";

        // Content metrics.
        $message .= "**Content Structure:**\n";
        $message .= "- Posts analyzed: {$content_analysis['post_count']}\n";
        $message .= "- Average paragraphs per article: {$content_analysis['avg_paragraphs']}\n";
        $message .= "- Average words per article: {$content_analysis['avg_words']}\n";
        $message .= "- Writing style: {$content_analysis['paragraph_style']} paragraphs\n\n";

        // Theme selectors.
        $selectors = $content_analysis['detected_selectors'] ?? [];
        $message .= "**Detected Theme Selectors:**\n";
        $message .= "- Content area: {$selectors['content']}\n";
        $message .= "- Sidebar: {$selectors['sidebar']}\n";
        $message .= "- Header: {$selectors['header']}\n";
        $message .= "- Footer: {$selectors['footer']}\n";
        $message .= "- Detection confidence: {$selectors['confidence']}\n\n";

        // User preferences gathered from conversation.
        $message .= "**User Preferences (from conversation):**\n";

        if ( ! empty( $wizard_data['site_type'] ) ) {
            $message .= "- Site type: {$wizard_data['site_type']}\n";
        }

        if ( isset( $wizard_data['priority'] ) ) {
            $priority = intval( $wizard_data['priority'] );
            $label    = $this->get_priority_label( $priority );
            $message .= "- UX vs Revenue priority: {$priority}/10 ({$label})\n";
        }

        if ( ! empty( $wizard_data['excluded_formats'] ) ) {
            $excluded = $this->format_array( $wizard_data['excluded_formats'] );
            $message .= "- Excluded formats: {$excluded}\n";
        }

        if ( ! empty( $wizard_data['ad_networks'] ) ) {
            $networks = $this->format_array( $wizard_data['ad_networks'] );
            $message .= "- Ad networks: {$networks}\n";
        }

        if ( ! empty( $wizard_data['traffic_pages'] ) ) {
            $pages = $this->format_array( $wizard_data['traffic_pages'] );
            $message .= "- High-traffic pages: {$pages}\n";
        }

        if ( ! empty( $wizard_data['background_color'] ) ) {
            $message .= "- Background color: {$wizard_data['background_color']}\n";
        }

        $message .= "\n**Your Task:**\n";
        $message .= "Based on this analysis, provide your ad placement recommendations using the [SLOTS_RECOMMENDATION] format. ";
        $message .= "Use the actual CSS selectors detected above. Recommend 3-6 high-impact placements.";

        return $message;
    }

    /**
     * Build the system prompt with encoded ad placement expertise.
     *
     * @return string System prompt for Claude.
     */
    public function build_system_prompt(): string {
        return <<<'PROMPT'
You are an expert ad placement consultant for web publishers. Recommend optimal ad slot configurations.

## CRITICAL: This Plugin's Scope
You are recommending WHERE to place placeholder divs with reserved space.
The actual ad code (AdSense, Ad Manager scripts) is handled separately by the ad network.
Your output = selectors + dimensions + positions. NOT ad code.

## IAB Standard Ad Sizes (2025)
Desktop Core:
- 300x250 (Medium Rectangle) - Most versatile, works everywhere
- 728x90 (Leaderboard) - Header/footer placement
- 160x600 (Wide Skyscraper) - Sidebar placement
- 300x600 (Half Page) - High-impact sidebar

Mobile Core:
- 320x50 (Mobile Banner) - Standard mobile leaderboard
- 320x100 (Large Mobile Banner) - Better visibility
- 300x250 (Medium Rectangle) - Works on mobile too

LEAN Principles: Lightweight, Encrypted, AdChoices-supported, Non-invasive

## Google AdSense Viewability Best Practices
- Viewability definition: 50% pixels visible for 1 second minimum
- Best position: Right above the fold (NOT top of page)
- Left/right sidebar placements outperform center
- Below-fold ads still valuable (47% average viewability)
- Maximum 15% of initial viewport for ads (Google guideline)

Position ranking by viewability:
1. Right above the fold (sidebar or in-content)
2. In-content after first few paragraphs
3. Sticky sidebar (desktop only)
4. Below-fold in-content
5. Footer area

## Better Ads Standards (MUST FOLLOW - NEVER recommend these)
Desktop violations:
- Pop-up ads
- Autoplay video ads with sound
- Prestitial ads with countdown
- Large sticky ads (>30% viewport)

Mobile violations (all above plus):
- Ad density >30% of content
- Flashing animated ads
- Poststitial ads with countdown
- Full-screen scrollover ads

## CLS Prevention (CRITICAL - This is the plugin's core purpose)
Every slot MUST have explicit width and height for space reservation. No exceptions.
This prevents Cumulative Layout Shift when ads load asynchronously.
Dimensions should match the intended ad size exactly.

## In-Content Ad Placement Guidelines
- For articles with 10+ paragraphs: Place first ad after paragraph 3-4
- Repeat in-content ads every 4-6 paragraphs (not too frequent)
- For short paragraphs (listicles): Can place more frequently
- For long-form content: 2-3 in-content ads maximum
- Never place ads that split a paragraph or heading from its content

## Sticky Ad Guidelines
- Desktop only (mobile sticky ads violate Better Ads)
- Sidebar sticky: 300x250 or 160x600
- Bottom sticky: 728x90 (ensure <30% viewport)
- User must be able to close or it must not obstruct content

## Output Format
Respond with ONLY a JSON array of slot objects. No explanatory text before or after.

Each object must have these fields:
{
  "name": "Human-readable slot name",
  "selector": "CSS selector from detected theme selectors",
  "position": "before|after|prepend|append",
  "width": integer (pixels),
  "height": integer (pixels),
  "device": "desktop|mobile|both",
  "sticky": boolean,
  "rationale": "Brief explanation of why this placement"
}

## Recommendations Guidelines
- Recommend 3-6 high-impact placements. Quality over quantity.
- Use the ACTUAL selectors from the content analysis, not generic guesses.
- Tailor in-content ad spacing to the site's actual paragraph patterns.
- Consider user's UX vs Revenue priority when determining ad density.
- Respect excluded formats from user preferences.
- Optimize for the ad networks the user specified.
PROMPT;
    }

    /**
     * Build the user message with wizard data and content analysis.
     *
     * @param array $wizard_data      User's wizard selections.
     * @param array $content_analysis Site content analysis results.
     * @return string User message for Claude.
     */
    public function build_user_message( array $wizard_data, array $content_analysis ): string {
        $message = "## Site Content Analysis (FROM ACTUAL ARTICLES)\n\n";

        // Content metrics.
        $message .= "Posts analyzed: {$content_analysis['post_count']}\n";
        $message .= "Average paragraphs per article: {$content_analysis['avg_paragraphs']}\n";
        $message .= "Average words per article: {$content_analysis['avg_words']}\n";

        // Heading pattern.
        $h2_count = $content_analysis['heading_pattern']['h2'] ?? 0;
        $h3_count = $content_analysis['heading_pattern']['h3'] ?? 0;
        $heading_pattern = "h2: {$h2_count} avg, h3: {$h3_count} avg";
        $message .= "Heading pattern: {$heading_pattern}\n";

        // Paragraph style.
        $message .= "Writing style: {$content_analysis['paragraph_style']} paragraphs\n";

        // Images.
        $has_images = $content_analysis['has_images'] ? 'yes' : 'no';
        $message .= "Articles have images: {$has_images}\n\n";

        // Theme selectors.
        $message .= "## Detected Theme Selectors\n\n";
        $selectors = $content_analysis['detected_selectors'];
        $message .= "Content area: {$selectors['content']}\n";
        $message .= "Sidebar: {$selectors['sidebar']}\n";
        $message .= "Header: {$selectors['header']}\n";
        $message .= "Footer: {$selectors['footer']}\n";
        $message .= "Detection confidence: {$selectors['confidence']}\n\n";

        // User preferences.
        $message .= "## User Preferences\n\n";

        // Traffic pages.
        $traffic_pages = $this->format_array( $wizard_data['traffic_pages'] ?? [] );
        $message .= "High-traffic page types: {$traffic_pages}\n";

        // Priority.
        $priority = intval( $wizard_data['priority'] ?? 5 );
        $priority_label = $this->get_priority_label( $priority );
        $message .= "UX vs Revenue priority: {$priority}/10 ({$priority_label})\n";

        // Excluded formats.
        $excluded = $this->format_array( $wizard_data['excluded_formats'] ?? [] );
        $excluded = empty( $excluded ) ? 'None' : $excluded;
        $message .= "Excluded ad formats: {$excluded}\n";

        // Ad networks.
        $networks = $this->format_array( $wizard_data['ad_networks'] ?? [] );
        $message .= "Ad networks in use: {$networks}\n";

        // Site type.
        $site_type = $wizard_data['site_type'] ?? 'blog';
        $message .= "Site type: {$site_type}\n";

        // Background color preference.
        $bg_color = $wizard_data['background_color'] ?? 'light_gray';
        $message .= "Background color preference: {$bg_color}\n\n";

        // Sample excerpts for context.
        if ( ! empty( $content_analysis['sample_excerpts'] ) ) {
            $message .= "## Sample Content Excerpts\n\n";
            foreach ( $content_analysis['sample_excerpts'] as $index => $excerpt ) {
                $num = $index + 1;
                $message .= "**Article {$num}: {$excerpt['title']}**\n";
                $message .= "{$excerpt['excerpt']}\n\n";
            }
        }

        $message .= "## Request\n\n";
        $message .= "Based on the above site analysis and user preferences, recommend optimal ad placements. ";
        $message .= "Output as a JSON array of slot objects following the schema specified in the system prompt.";

        return $message;
    }

    /**
     * Format an array as a comma-separated string.
     *
     * @param array $items Array of items.
     * @return string Formatted string.
     */
    private function format_array( array $items ): string {
        if ( empty( $items ) ) {
            return '';
        }

        // Convert snake_case to readable labels.
        $formatted = array_map(
            function ( $item ) {
                return ucwords( str_replace( '_', ' ', $item ) );
            },
            $items
        );

        return implode( ', ', $formatted );
    }

    /**
     * Get a human-readable label for the priority value.
     *
     * @param int $priority Priority value (1-10).
     * @return string Priority label.
     */
    private function get_priority_label( int $priority ): string {
        if ( $priority <= 3 ) {
            return 'UX-focused - fewer, less intrusive ads';
        } elseif ( $priority <= 6 ) {
            return 'Balanced approach';
        } else {
            return 'Revenue-focused - maximize ad placements';
        }
    }
}
