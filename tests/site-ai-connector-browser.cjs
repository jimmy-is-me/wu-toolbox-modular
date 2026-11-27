/* Disposable WordPress only. Configure a local browser and the fixture via environment variables. */
const assert = require('node:assert/strict');
const {createHash, randomBytes} = require('node:crypto');
const {chromium} = require('playwright-core');
const {Client} = require('@modelcontextprotocol/sdk/client/index.js');
const {StreamableHTTPClientTransport} = require('@modelcontextprotocol/sdk/client/streamableHttp.js');

async function main() {
    const base = process.env.SAC_TEST_URL;
    const login = process.env.SAC_TEST_LOGIN;
    const password = process.env.SAC_TEST_PASSWORD;
    const executablePath = process.env.SAC_TEST_CHROME;
    assert.ok(base && login && password && executablePath, 'Configure the disposable OAuth fixture.');
    assert.ok(['localhost', '127.0.0.1'].includes(new URL(base).hostname), 'The browser fixture only runs locally.');
    const browser = await chromium.launch({executablePath});
    try {
        const context = await browser.newContext({ permissions: ['clipboard-read', 'clipboard-write'] });
        const page = await context.newPage();
        page.setDefaultTimeout(12000);
        await context.route('**/*', route => new URL(route.request().url()).origin === new URL(base).origin ? route.continue() : route.abort());
        const redirect = 'https://chatgpt.com/connector_platform_oauth_redirect';
        await context.route(redirect + '**', route => route.fulfill({ status: 200, body: 'Simulated registration callback' }));
        const resource = base + '/wp-json/site-ai/v1/mcp';
        const unauthenticated = await context.request.post(resource, {data: {jsonrpc: '2.0', id: 1, method: 'ping'}});
        assert.equal(unauthenticated.status(), 401);
        assert.match(unauthenticated.headers()['cache-control'], /no-store/);
        const prmUrl = unauthenticated.headers()['www-authenticate'].match(/resource_metadata="([^"]+)"/)[1];
        const prm = await (await context.request.get(prmUrl)).json();
        assert.equal(prm.resource, resource);
        const issuer = new URL(prm.authorization_servers[0]);
        const asmUrl = issuer.origin + '/.well-known/oauth-authorization-server' + issuer.pathname;
        const asm = await (await context.request.get(asmUrl)).json();
        assert.equal(asm.issuer, issuer.href);
        assert.deepEqual(asm.code_challenge_methods_supported, ['S256']);
        const registered = await context.request.post(asm.registration_endpoint, {data: {
            client_name: 'OAuth fixture Browser',
            redirect_uris: [redirect],
            token_endpoint_auth_method: 'client_secret_basic',
            grant_types: ['authorization_code', 'refresh_token'],
            response_types: ['code'],
        }});
        assert.equal(registered.status(), 201);
        const client = await registered.json();
        const basic = 'Basic ' + Buffer.from(client.client_id + ':' + client.client_secret).toString('base64');
        const verifier = randomBytes(32).toString('base64url');
        const state = randomBytes(18).toString('base64url');
        const authorization = new URL(asm.authorization_endpoint);
        const all = asm.scopes_supported.join(' ');
        for (const [key, value] of Object.entries({
            client_id: client.client_id, redirect_uri: redirect, resource,
            response_type: 'code', scope: all, state,
            code_challenge: createHash('sha256').update(verifier).digest('base64url'), code_challenge_method: 'S256',
        })) authorization.searchParams.set(key, value);
        await page.goto(authorization.href);
        assert.ok(new URL(page.url()).pathname.endsWith('/wp-login.php'));
        await page.locator('#user_login').fill(login);
        await page.locator('#user_pass').fill(password);
        await page.locator('#wp-submit').click();
        await page.getByRole('heading', {name: '授權 AI 應用程式'}).waitFor();
        await page.getByRole('button', {name: '拒絕授權'}).click();
        let callback = new URL(page.url());
        assert.equal(callback.searchParams.get('error'), 'access_denied');
        assert.equal(callback.searchParams.get('iss'), asm.issuer);
        assert.equal(callback.searchParams.get('state'), state);

        await page.goto(authorization.href);
        await page.getByRole('button', {name: '同意授權'}).click();
        callback = new URL(page.url());
        assert.equal(callback.searchParams.get('iss'), asm.issuer);
        assert.equal(callback.searchParams.get('state'), state);
        assert.ok(callback.searchParams.get('code'));
        const tokenForm = {
            grant_type: 'authorization_code', resource,
            code: callback.searchParams.get('code'), redirect_uri: redirect, code_verifier: verifier,
        };
        const exchange = () => context.request.post(asm.token_endpoint, {form: tokenForm, headers: {Authorization: basic}});
        const parallel = await Promise.all([exchange(), exchange()]);
        assert.deepEqual(parallel.map(response => response.status()).sort(), [200, 400]);
        const tokens = await parallel.find(response => response.status() === 200).json();
        const auth = {Authorization: 'Bearer ' + tokens.access_token};
        const notify = await context.request.post(resource, {headers: auth, data: {jsonrpc: '2.0', method: 'notifications/initialized'}});
        assert.equal(notify.status(), 202);
        assert.equal((await notify.body()).length, 0);

        const sdk = new Client({name: 'wutm fixture SDK', version: '1.0.0'});
        await sdk.connect(new StreamableHTTPClientTransport(new URL(resource), {requestInit: {headers: auth}}));
        await sdk.ping();
        const tools = new Map((await sdk.listTools()).tools.map(entry => [entry.name, entry]));
        assert.ok(tools.has('create_content'));
        assert.equal(tools.get('create_content').annotations.readOnlyHint, false);
        assert.deepEqual(tools.get('upload_media')._meta['openai/fileParams'], ['file']);
        const tool = async (name, args) => {
            const result = await sdk.callTool({name, arguments: args});
            assert.equal(Boolean(result.isError), false, result.structuredContent?.message);
            return result.structuredContent;
        };
        const modern = async (method, params, options = {}) => {
            const version = options.version || '2026-07-28';
            return context.request.post(resource, {
                headers: {...auth, 'MCP-Protocol-Version': version, 'Mcp-Method': options.header || method},
                data: {jsonrpc: '2.0', id: 'modern-1', method,
                    params: {...params, _meta: {'io.modelcontextprotocol/protocolVersion': version}}},
            });
        };
        const discovery = await (await modern('server/discover', {})).json();
        assert.ok(discovery.result.supportedVersions.includes('2026-07-28'));
        assert.equal(discovery.result._meta['io.modelcontextprotocol/serverInfo'].name, 'wu-toolbox-site-ai');
        assert.equal((await modern('tools/list', {}, {header: 'tools/call'})).status(), 400);
        const toolList = await modern('tools/list', {});
        assert.equal(toolList.status(), 200);
        assert.ok((await toolList.json()).result.tools.some(entry => entry.name === 'verify_connection'));

        const types = (await tool('list_content_types', {})).data.types;
        const isLab = types.some(type => type.post_type === 'wumetax_lab');
        const postType = isLab ? 'wumetax_lab' : 'post';
        const title = 'OAuth fixture ' + Date.now();
        const fields = {post_type: postType, title, content: '<!-- wp:paragraph --><p>OAuth integration fixture</p><!-- /wp:paragraph -->'};
        if (isLab) {
            const description = (await tool('describe_content_type', {post_type: postType})).data;
            assert.ok(description.custom_fields.title_en_us);
            const projectType = (await tool('list_content_terms', {post_type: postType, taxonomy: 'wulab_type'})).data.items[0];
            const technology = (await tool('list_content_terms', {post_type: postType, taxonomy: 'wulab_tech'})).data.items[0];
            fields.terms = {wulab_type: [projectType.id], wulab_tech: [technology.id]};
            fields.custom_fields = {title_en_us: 'Fixture Project', demo_url: 'https://example.com/demo', repo_url: 'https://example.com/source',
                status: 'prototype', order: 101, challenge: 'Challenge', approach: 'Approach', result: 'Result'};
        }
        const created = await tool('create_content', fields);
        const id = created.data.id;
        assert.equal(created.data.status, 'draft');
        assert.ok(created.data.edit_url);
        if (isLab) {
            const lab = (await tool('get_content', {post_type: postType, id})).data;
            assert.equal(lab.custom_fields.title_en_us, 'Fixture Project');
            assert.equal(lab.custom_fields.order, 101);
            assert.equal(lab.terms.wulab_tech[0].id, fields.terms.wulab_tech[0]);
        }
        await tool('update_content_faq', {post_type: postType, id, faqs: [{question: 'Fixture question', answer: 'Fixture answer'}]});
        if (process.env.SAC_TEST_SEO === '1') await tool('update_content_seo', {post_type: postType, id, title: 'Fixture SEO', description: 'Fixture SEO description'});
        let uploadedId;
        if (process.env.SAC_TEST_IMAGE) {
            const upload = (await tool('create_upload_link', {})).data;
            await page.goto(upload.upload_url);
            await page.locator('input[type=file]').setInputFiles(process.env.SAC_TEST_IMAGE);
            await page.locator('input[name=alt_text]').fill('Fixture ALT');
            await page.getByRole('button', {name: '上傳圖片'}).click();
            assert.match(await page.locator('body').innerText(), /已上傳圖片/);
            const receipt = (await tool('check_upload_link', {token: upload.token})).data;
            assert.equal(receipt.status, 'uploaded');
            assert.equal(receipt.alt_text, 'Fixture ALT');
            assert.equal(receipt.upload_status, 'completed');
            assert.ok(receipt.width > 0 && receipt.height > 0 && receipt.file_size > 0);
            uploadedId = receipt.media_id;
            await tool('set_content_featured_image', {post_type: postType, id, featured_image_id: receipt.attachment_id});
            assert.equal((await tool('get_content', {post_type: postType, id})).data.featured_image_id, receipt.attachment_id);
            const reused = await page.goto(upload.upload_url);
            assert.equal(reused.status(), 200);
            assert.match(await page.locator('body').innerText(), /已上傳圖片/);
        }

        const prepare = await sdk.callTool({name: 'publish_content', arguments: {post_type: postType, id}});
        assert.equal(prepare.structuredContent.code, 'sac_confirmation_required');
        await page.goto(prepare.structuredContent.review_url);
        await page.getByRole('button', {name: '確認變更'}).click();
        assert.match(await page.locator('body').innerText(), /已確認/);
        const published = await tool('publish_content', {post_type: postType, id, confirmation_token: prepare.structuredContent.confirmation_token});
        assert.equal(published.data.status, 'publish');
        const replay = await sdk.callTool({name: 'publish_content', arguments: {post_type: postType, id, confirmation_token: prepare.structuredContent.confirmation_token}});
        assert.ok(replay.isError);
        const operation = await tool('get_operation_result', {id: published.operation_id});
        assert.equal(operation.data.target_id, String(id));

        const subject = {post_type: postType, id};
        const call = (name, args) => sdk.callTool({name, arguments: {...subject, ...args}});
        const approveLegacy = async pending => {
            assert.equal(pending.structuredContent.code, 'sac_confirmation_required');
            await page.goto(pending.structuredContent.review_url);
            await page.getByRole('button', {name: '確認變更'}).click();
            return pending.structuredContent.confirmation_token;
        };
        const longContent = '<!-- wp:paragraph --><p>' + '長篇內容 '.repeat(7000) + 'LAST-CHAPTER-431</p><!-- /wp:paragraph -->'
            + '<!-- wp:greenshift-blocks/row {"className":"fixture-preserved"} --><section><!-- wp:block {"ref":99} /--></section><!-- /wp:greenshift-blocks/row -->';
        const long = await call('update_content', {content: longContent});
        await page.goto(long.structuredContent.review_url);
        assert.match(await page.locator('body').textContent(), /LAST-CHAPTER-431/);
        const independent = await call('update_content_faq', {faqs: [{question: 'Independent 431', answer: 'Approval survives a separate native update'}]});
        const titleChange = await call('update_content', {title: title + ' revised'});
        const longToken = await approveLegacy(long);
        const faqToken = await approveLegacy(independent);
        const titleToken = await approveLegacy(titleChange);
        await tool('update_content', {...subject, title: title + ' revised', confirmation_token: titleToken});
        await tool('update_content', {...subject, content: longContent, confirmation_token: longToken});
        await tool('update_content_faq', {...subject, faqs: [{question: 'Independent 431', answer: 'Approval survives a separate native update'}], confirmation_token: faqToken});

        const conflictA = await call('update_content', {title: 'Concurrent A'});
        const conflictB = await call('update_content', {title: 'Concurrent B'});
        const tokenA = await approveLegacy(conflictA);
        const tokenB = await approveLegacy(conflictB);
        await tool('update_content', {...subject, title: 'Concurrent A', confirmation_token: tokenA});
        const blocked = await call('update_content', {title: 'Concurrent B', confirmation_token: tokenB});
        assert.equal(blocked.structuredContent.code, 'sac_confirmation_version_conflict');

        const index = (await tool('list_content_blocks', subject)).data;
        const paragraph = index.blocks.find(block => block.name === 'core/paragraph');
        const table = '<!-- wp:table --><figure class="wp-block-table"><table><tbody><tr><td>Comparison 431</td></tr></tbody></table></figure><!-- /wp:table -->';
        const batchArgs = {...subject, idempotency_key: randomBytes(16).toString('hex'), content: {excerpt: 'Batch excerpt'},
            faqs: Array.from({length: 15}, (_, i) => ({question: 'Question ' + i, answer: 'Answer ' + i})), block_version: index.version,
            block_edits: [{operation: 'insert_after', path: paragraph.path, hash: paragraph.hash, markup: table}]};
        if (uploadedId) {
            batchArgs.media = {id: uploadedId, alt_text: 'Updated in batch', caption: 'Batch caption'};
            batchArgs.content.featured_image_id = uploadedId;
        }
        if (process.env.SAC_TEST_SEO === '1') batchArgs.seo = {description: 'Batch SEO description', ...(uploadedId ? {og_image_id: uploadedId} : {})};
        const pending = (await tool('prepare_content_changes', batchArgs)).data;
        assert.equal(pending.approval_status, 'pending');
        assert.equal((await tool('prepare_content_changes', batchArgs)).data.operation_id, pending.operation_id);
        await page.goto(pending.confirmation_url);
        await page.getByRole('button', {name: '核准並套用'}).click();
        assert.match(await page.locator('body').innerText(), /已儲存並核對/);
        const applied = (await tool('verify_content_changes', {operation: pending.operation_id})).data;
        assert.equal(applied.approval_status, 'applied');
        assert.ok(Object.values(applied.verification).every(state => state === 'verified'));
        if (uploadedId) {
            assert.equal((await tool('get_media', {id: uploadedId})).data.alt_text, 'Updated in batch');
            assert.equal(applied.verification['content.featured_image_id'], 'verified');
        }
        const readback = (await tool('get_content', subject)).data;
        assert.ok(readback.content.includes(table));
        assert.ok(readback.content.includes('<!-- wp:greenshift-blocks/row {"className":"fixture-preserved"} --><section><!-- wp:block {"ref":99} /--></section><!-- /wp:greenshift-blocks/row -->'));
        assert.equal((await tool('get_content_faq', subject)).data.faqs.length, 15);
        assert.equal((await tool('prepare_content_changes', batchArgs)).data.approval_status, 'applied');
        const analysis = (await tool('analyze_content', {...subject, include: ['outline', 'seo', 'gsc']})).data;
        assert.equal(analysis.sections.outline.state, 'verified');
        assert.equal(analysis.sections.gsc.state, 'unavailable');

        await page.goto(base + '/wp-admin/admin.php?page=wu-ai-operation-log');
        assert.equal(new URL(page.url()).searchParams.get('tab'), 'logs');
        const row = page.getByRole('row').filter({hasText: postType + ' ' + id}).filter({hasText: 'publish_content'}).filter({hasText: '成功'});
        const edit = await row.getByRole('link', {name: '編輯'}).getAttribute('href');
        assert.equal(new URL(edit).searchParams.get('post'), String(id));
        await page.goto(base + '/wp-admin/admin.php?page=site-ai-connector&tab=connection');
        const copy = page.locator('[data-copy=sac-mcp-connect]');
        await copy.click();
        await page.getByRole('button', {name: '已複製', exact: true}).waitFor();
        assert.equal(await page.evaluate(() => navigator.clipboard.readText()), resource);
        await page.locator('.sac-inspector summary').click();
        const secondCopy = page.locator('.sac-inspector .sac-copy').first();
        await secondCopy.click();
        await secondCopy.getByText('已複製', {exact: true}).waitFor();
        assert.equal(await copy.innerText(), '複製');
        for (const width of [1440, 1024, 390]) {
            await page.setViewportSize({width, height: 900});
            const aligned = await page.locator('.sac-connection-endpoints .sac-copy-field').evaluateAll(rows => rows.every(row => {
                const label = row.querySelector('label').getBoundingClientRect();
                const field = row.querySelector('input').getBoundingClientRect();
                const button = row.querySelector('button').getBoundingClientRect();
                const verticalOffset = Math.abs((field.top + field.bottom) / 2 - (button.top + button.bottom) / 2);
                return field.width > 55 && button.left >= field.right && verticalOffset <= 4
                    && button.right <= row.getBoundingClientRect().right + 1 && (window.innerWidth > 782 || label.bottom <= field.top);
            }));
            assert.ok(aligned, 'Copy rows align at width ' + width);
        }

        const revoked = await context.request.post(asm.revocation_endpoint, {
            headers: {Authorization: basic}, form: {token: tokens.refresh_token},
        });
        assert.equal(revoked.status(), 200);
        assert.equal((await context.request.get(resource, {headers: auth})).status(), 401);
        await sdk.close();
        console.log('Browser OAuth consent and denial, one-use code, SDK calls, modern headers, authenticated upload, long content reviews, conflicts, batch apply, audit link and revocation passed. Lab fixture: ' + isLab);
    } finally {
        await browser.close();
    }
}

main().catch(error => {
    console.error(error.message);
    process.exitCode = 1;
});
