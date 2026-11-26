document.querySelectorAll('.sac-admin .sac-copy').forEach((button) => {
    button.addEventListener('click', async () => {
        const source = document.getElementById(button.dataset.copy);
        if (!source || !source.value) return;
        try {
            await navigator.clipboard.writeText(source.value);
            button.textContent = '已複製';
        } catch {
            source.focus();
            source.select();
            button.textContent = '已選取，請手動複製';
        }
    });
});

document.querySelectorAll('.sac-builder').forEach((builder) => {
    const types = JSON.parse(builder.dataset.types);
    const select = builder.querySelector('.sac-builder-type');
    const input = builder.querySelector('.sac-builder-request');
    const id = builder.querySelector('.sac-builder-id');
    const fields = builder.querySelector('.sac-builder-fields');
    const output = builder.querySelector('textarea[readonly]');
    const update = () => {
        const type = types[select.value];
        const native = Object.keys(type.fields).filter((name) => type.fields[name]);
        const custom = Object.entries(type.custom_fields);
        const required = [...type.required_on_create, ...custom.filter(([, field]) => field.required).map(([name]) => name)];
        const bindings = custom.map(([name, field]) => name + (field.write ? '' : '（需補設綁定或從後台編輯）'));
        const available = [...native, ...bindings].join('、') || '無';
        const requiredText = required.join('、') || '依工具檢查';
        const taxonomies = Object.keys(type.taxonomies).join('、') || '無';
        fields.textContent = '可用欄位 ' + available + '。建立必填 ' + requiredText + '。分類法 ' + taxonomies + '。';
        const steps = [
            '在已連線的 WordPress 處理「' + type.label + '」(' + select.value + ')。',
            '先呼叫 list_content_types，確認金鑰允許本次操作，再用 describe_content_type 核對可用及必填欄位。若要指派分類，先用 list_content_terms 取得已存在的分類 ID。',
            fields.textContent,
        ];
        if (id) {
            steps.push('用 get_content 查詢 ID ' + (id.value || '［請填寫］') + ' 並核對類型、標題與狀態。先列出原值和預計變更，等我明確確認後呼叫 update_content，只提交本次指定欄位。');
        } else {
            steps.push('先列出預計建立的內容和欄位，請我提供缺少的必填值。等我明確確認後呼叫 create_content，將狀態設為 draft。');
        }
        steps.push('需求：' + (input.value.trim() || '［請填寫］'), '成功後回報類型、標題、ID、狀態與 WordPress 編輯連結。若失敗，回報實際原因與處理入口。');
        output.value = steps.join('\n');
    };
    select.addEventListener('change', update);
    input.addEventListener('input', update);
    if (id) id.addEventListener('input', update);
    update();
});
