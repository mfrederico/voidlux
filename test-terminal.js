const { chromium } = require('playwright');

(async () => {
  const browser = await chromium.launch({ headless: false });
  const context = await browser.newContext();
  const page = await context.newPage();

  // Enable console logging
  page.on('console', msg => console.log('BROWSER:', msg.text()));
  page.on('pageerror', err => console.log('PAGE ERROR:', err.message));

  try {
    console.log('1. Opening dashboard...');
    await page.goto('http://localhost:9090', { waitUntil: 'networkidle' });
    await page.screenshot({ path: '/tmp/step1-dashboard.png' });

    console.log('2. Looking for Setup Authentication button...');
    const authButton = await page.locator('button:has-text("Setup Authentication")').first();
    if (!await authButton.isVisible()) {
      console.log('ERROR: Setup Authentication button not visible!');
      const html = await page.content();
      console.log('Page HTML:', html.substring(0, 500));
    } else {
      console.log('Button found, clicking...');
      await authButton.click();
      await page.waitForTimeout(1000);
      await page.screenshot({ path: '/tmp/step2-after-click.png' });

      console.log('3. Waiting for terminal modal...');
      try {
        await page.waitForSelector('#terminal-modal.active', { timeout: 5000 });
        console.log('Terminal modal appeared!');
      } catch (e) {
        console.log('Terminal modal did NOT appear after 5s');
      }

      const modal = await page.locator('#terminal-modal');
      const hasActiveClass = await modal.evaluate(el => el.classList.contains('active'));
      const computedDisplay = await modal.evaluate(el => window.getComputedStyle(el).display);
      console.log('Modal has active class:', hasActiveClass);
      console.log('Modal computed display:', computedDisplay);

      await page.waitForTimeout(3000); // Wait for terminal to initialize
      await page.screenshot({ path: '/tmp/step3-terminal-state.png' });

      if (hasActiveClass) {

        // Check terminal content
        const terminalDiv = await page.locator('#terminal');
        console.log('Terminal div found:', await terminalDiv.count());

        // Check WebSocket connection
        const wsState = await page.evaluate(() => {
          return {
            wsExists: typeof terminalWs !== 'undefined',
            wsState: typeof terminalWs !== 'undefined' ? terminalWs.readyState : 'N/A',
            termExists: typeof term !== 'undefined'
          };
        });
        console.log('WebSocket state:', wsState);

        // Wait for terminal to be ready
        await page.waitForTimeout(2000);
        await page.screenshot({ path: '/tmp/step4-before-typing.png' });

        // Test keyboard input
        console.log('\nTesting keyboard input...');
        await terminalDiv.click(); // Focus the terminal
        await page.waitForTimeout(500);

        // Type a command
        console.log('Typing "1\\n" to select theme...');
        await page.keyboard.type('1');
        await page.waitForTimeout(500);
        await page.keyboard.press('Enter');

        await page.waitForTimeout(2000);
        await page.screenshot({ path: '/tmp/step5-after-typing.png' });

        // Try another command
        console.log('Typing "/help\\n"...');
        await page.keyboard.type('/help');
        await page.waitForTimeout(500);
        await page.keyboard.press('Enter');

        await page.waitForTimeout(2000);
        await page.screenshot({ path: '/tmp/step6-final.png' });

        console.log('Keyboard test complete!');
      }
    }

    console.log('\nScreenshots saved to /tmp/step*.png');
    console.log('Press Ctrl+C to close browser...');
    await page.waitForTimeout(30000);

  } catch (error) {
    console.error('Test failed:', error);
    await page.screenshot({ path: '/tmp/error.png' });
  } finally {
    await browser.close();
  }
})();
