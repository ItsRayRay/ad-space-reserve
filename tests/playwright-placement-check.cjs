const { chromium } = require('playwright');

const baseUrl = process.env.ASR_BASE_URL || 'http://localhost:8090';

function assert(condition, message) {
  if (!condition) {
    throw new Error(message);
  }
}

(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
  const errors = [];

  page.on('pageerror', (error) => errors.push(error.message));
  page.on('console', (message) => {
    if (message.type() === 'error') {
      errors.push(message.text());
    }
  });

  await page.goto(`${baseUrl}/asr-target-alpha/`, { waitUntil: 'networkidle' });

  const targetSlots = page.locator('.asr-slot-target-demo');
  assert(await targetSlots.count() === 1, 'Target page should contain exactly one target-demo slot.');

  const box = await targetSlots.first().boundingBox();
  assert(box && box.height >= 360, `Target slot should reserve at least 360px, got ${box?.height ?? 0}.`);

  const placement = await page.evaluate(() => {
    const content = document.querySelector('.entry-content');
    const slot = document.querySelector('.asr-slot-target-demo');
    const children = [...content.childNodes].map((node) => {
      if (node.nodeType === Node.ELEMENT_NODE) {
        return node.matches('.asr-slot-target-demo') ? 'slot' : node.textContent.trim();
      }
      return '';
    }).filter(Boolean);

    return {
      insideContent: Boolean(content && slot && content.contains(slot)),
      slotIndex: children.indexOf('slot'),
      targetTwoIndex: children.findIndex((text) => text.indexOf('Target two') === 0),
      targetThreeIndex: children.findIndex((text) => text.indexOf('Target three') === 0),
      legacySlots: document.querySelectorAll('.asr-desktop-billboard-btf').length,
      borderTopStyle: getComputedStyle(slot).borderTopStyle,
    };
  });

  assert(placement.insideContent, 'Target slot should be inside .entry-content.');
  assert(placement.slotIndex > placement.targetTwoIndex, 'Target slot should come after paragraph 2.');
  assert(placement.slotIndex < placement.targetThreeIndex, 'Target slot should come before paragraph 3.');
  assert(placement.legacySlots === 0, 'Old child-theme ASR slots should not appear during latest-plugin test.');
  assert(placement.borderTopStyle === 'dashed', 'Target slot should use obvious dashed ad styling.');

  await page.goto(`${baseUrl}/asr-other-alpha/`, { waitUntil: 'networkidle' });
  assert(await page.locator('.asr-slot-target-demo').count() === 0, 'Non-target page should not contain target-demo slot.');

  assert(errors.length === 0, `Browser errors were reported: ${errors.join('; ')}`);

  await browser.close();
  console.log('Playwright placement checks passed.');
})().catch(async (error) => {
  console.error(error);
  process.exit(1);
});
