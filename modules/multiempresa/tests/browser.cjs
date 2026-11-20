// Optional visual smoke test; run against the disposable test server only.
const fs = require('fs');
const path = require('path');
const {chromium} = require(require.resolve('playwright', {paths: [process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES || process.cwd()]}));
(async () => {
 const browser = await chromium.launch({executablePath: process.env.CHROMIUM_PATH || '/usr/bin/chromium', args: ['--no-sandbox','--disable-dev-shm-usage']});
 try {
  const page=await browser.newPage({viewport:{width:1440,height:1000}});
  await page.goto('http://127.0.0.1:8879/admin/authentication');
  await page.locator('input[name=email]').fill('admin@example.test');
  await page.locator('input[name=password]').fill('Native-test-password-26');
  await page.locator('button[type=submit]').click();
  await page.goto('http://127.0.0.1:8879/admin/multiempresa');
  await page.locator('.multiempresa-panel').waitFor();
  await page.locator('#pme-grid-body tr .pme-company-cell').first().waitFor();
  if (process.argv[2]) await page.screenshot({path:path.join(process.argv[2],'panel-desktop.png'),fullPage:true});
  for(const tab of ['companies','organizations','settings','logs','access','modules','integrations','events','updates','audit']){
   await page.goto('http://127.0.0.1:8879/admin/multiempresa?tab='+tab);await page.locator('.pme-navigation').waitFor();
   if(await page.locator('.pme-navigation a').count()!==10)throw new Error('Missing tab '+tab);
   if(tab==='settings')await page.locator('#pme-global-form .pme-settings-group').first().evaluate(el=>el.open=true);
   if(process.argv[2])await page.screenshot({path:path.join(process.argv[2],'tab-'+tab+'.png'),fullPage:true});
  }
  await page.goto('http://127.0.0.1:8879/admin/multiempresa');await page.locator('#pme-grid-body [data-edit]').first().waitFor();
  await page.locator('#pme-search').fill('Gama');
  await page.getByText('1–1 de 1 empresas',{exact:true}).waitFor();
  await page.locator('#pme-search').fill('');
  await page.locator('#pme-new').click();
  const wizard=page.locator('#pme-wizard');
  await wizard.locator('select[name=admin_mode]').selectOption('current');
  await wizard.locator('input[name=name]').fill('Empresa de teste visual');
  await wizard.locator('select[name=organization_id]').selectOption({index:1});
  if(await wizard.locator('input[name=slug]').inputValue()!=='empresa-de-teste-visual')throw new Error('Automatic slug failed');
  await page.locator('#pme-forward').click();
  if(await wizard.locator('input[name=host]').isEnabled()) {
   await wizard.locator('input[name=host]').fill('127.0.0.1');
   await wizard.locator('input[name=database]').fill('visual_fixture');
   await wizard.locator('input[name=username]').fill('test_user');
   await wizard.locator('input[name=db_password]').fill('never-show-this-fixture');
   await wizard.locator('input[name=prefix]').fill('visual_');
  }
  await page.locator('#pme-forward').click();
  if(await wizard.locator('input[name="modules[]"][value=multiempresa]').count())throw new Error('Central module was offered');
  await page.locator('#pme-select-all').click();
  const eligible=await wizard.locator('input[name="modules[]"]:not(:disabled)').count();
  if(await wizard.locator('input[name="modules[]"]:checked').count()!==eligible)throw new Error('Select all failed');
  await page.locator('#pme-unselect-all').click();
  if(await wizard.locator('input[name="modules[]"]:checked').count())throw new Error('Deselect all failed');
  await wizard.locator('input[name="modules[]"][value=isolation_probe]').check();
  await wizard.locator('input[name=modules_reviewed]').check();
  await page.locator('#pme-forward').click();
  const review=await page.locator('#pme-review').innerText();
  if(!review.includes('Empresa de teste visual') || !review.includes('Isolation Probe') || review.includes('never-show-this-fixture'))throw new Error('Wizard review failed');
  if(process.argv[2])await page.screenshot({path:path.join(process.argv[2],'wizard-review.png'),fullPage:true});
  await page.keyboard.press('Escape');
  if(await wizard.isVisible())throw new Error('Escape did not close wizard');
  await page.locator('[data-edit]').first().click();
  await page.locator('#pme-editor input[name=name]:enabled').waitFor();
  await page.getByRole('button',{name:'Destino dos dados',exact:true}).click();
  await page.locator('#pme-edit-connection').waitFor();
  await page.keyboard.press('Escape');
  await page.setViewportSize({width:390,height:844});
  if(await page.evaluate(()=>document.documentElement.scrollWidth>window.innerWidth+2))throw new Error('Page overflows mobile viewport');
  if (process.argv[2]) await page.screenshot({path:path.join(process.argv[2],'panel-mobile.png'),fullPage:true});
  await page.locator('#pme-new').click();
  if(process.argv[2])await page.screenshot({path:path.join(process.argv[2],'wizard-mobile.png'),fullPage:true});
  await page.keyboard.press('Escape');
  console.log('PASS browser grid search, wizard review, editor, Escape and responsive layouts; no provisioning submitted');
 } finally {await browser.close();}
})().catch(e=>{console.error(e.message);process.exitCode=1;});
