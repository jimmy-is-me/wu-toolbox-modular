(() => {
    const { createElement: el } = wp.element;
    const { InnerBlocks, useBlockProps, InspectorControls } = wp.blockEditor;
    const { PanelBody, ToggleControl, SelectControl } = wp.components;
    const attributes = {
        zoom: { type: 'boolean', default: true },
        mobileColumns: { type: 'number', default: 2 }
    };
    const classes = ({ zoom, mobileColumns }) =>
        'wutm-gallery-mobile-' + (mobileColumns === 1 ? 1 : 2) + (zoom ? ' wutm-gallery-zoom' : '');
    wp.blocks.registerBlockType('wu-toolbox/advanced-gallery', {
        title: '進階相簿',
        category: 'media',
        icon: 'format-gallery',
        description: '透過媒體庫選取多張圖片，支援排序、響應式版面、滑鼠放大及原生燈箱。',
        attributes,
        edit({ attributes: values, setAttributes }) {
            return el('div', useBlockProps({ className: classes(values) }),
                el(InspectorControls, {}, el(PanelBody, { title: '相簿外觀' },
                    el(ToggleControl, { label: '滑鼠移入放大', checked: values.zoom, onChange: zoom => setAttributes({ zoom }) }),
                    el(SelectControl, { label: '手機欄數', value: String(values.mobileColumns),
                        options: [{ label: '1 欄', value: '1' }, { label: '2 欄', value: '2' }],
                        onChange: mobileColumns => setAttributes({ mobileColumns: Number(mobileColumns) }) })
                )),
                el(InnerBlocks, {
                allowedBlocks: ['core/gallery'],
                template: [['core/gallery', { columns: 3, imageCrop: true }]],
                templateLock: 'all'
            }));
        },
        save({ attributes: values }) {
            return el('div', useBlockProps.save({ className: classes(values) }), el(InnerBlocks.Content));
        }
    });
})();
