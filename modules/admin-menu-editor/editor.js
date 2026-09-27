(function($){
    'use strict';
    const $form = $('#wutm-menu-editor-form');
    if (!$form.length) return;

    $('.wutm-menu-top').sortable({items:'> .wutm-menu-group',handle:'> .wutm-menu-item .wutm-menu-handle',placeholder:'wutm-menu-placeholder'});
    $('.wutm-menu-section-items').sortable({items:'> .wutm-menu-item',handle:'.wutm-menu-handle',placeholder:'wutm-menu-placeholder'});

    $form.on('submit',function(event){
        const data = {items:{},top_order:[],sub_order:{}};
        const baseline = JSON.parse($('#wutm-menu-base-order').val() || '{}');
        $('.wutm-menu-top > .wutm-menu-group').each(function(){
            const $group=$(this), $top=$group.children('.wutm-menu-item').first();
            data.top_order.push($top.attr('data-id'));
            const $children=$group.children('.wutm-menu-children');
            if ($children.length) {
                const parent=$children.attr('data-parent');
                data.sub_order[parent]=[];
                $children.find('.wutm-menu-section-items > .wutm-menu-item').each(function(){data.sub_order[parent].push($(this).attr('data-id'));});
            }
        });
        $('.wutm-menu-item').each(function(){
            const $item=$(this), id=$item.attr('data-id');
            data.items[id]={label:$item.find('.wutm-menu-label').val().trim(),hidden:$item.find('.wutm-menu-hidden').prop('checked')};
        });
        if (!data.top_order.length) {event.preventDefault();return;}
        if (JSON.stringify(data.top_order) === JSON.stringify(baseline.top_order || [])) delete data.top_order;
        Object.keys(data.sub_order).forEach(function(parent){
            if (JSON.stringify(data.sub_order[parent]) === JSON.stringify((baseline.sub_order || {})[parent] || [])) delete data.sub_order[parent];
        });
        if (!Object.keys(data.sub_order).length) delete data.sub_order;
        $('#wutm-menu-config').val(JSON.stringify(data));
    });
})(jQuery);
