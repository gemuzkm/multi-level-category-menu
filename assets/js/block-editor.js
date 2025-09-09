(function(wp) {
    const { registerBlockType } = wp.blocks;
    const { InspectorControls } = wp.blockEditor;
    const { PanelBody, SelectControl, RangeControl, ToggleControl } = wp.components;
    const { __ } = wp.i18n;
    const { createElement: el } = wp.element;

    registerBlockType('mlcm/menu-block', {
        title: __('Category Menu', 'mlcm'),
        icon: 'menu',
        category: 'widgets',
        
        attributes: {
            layout: {
                type: 'string',
                default: 'vertical'
            },
            levels: {
                type: 'number',
                default: 3
            },
            modal: {
                type: 'boolean',
                default: false
            }
        },

        edit: function(props) {
            const { attributes, setAttributes } = props;

            return [
                // Панель настроек в инспекторе
                el(InspectorControls, null,
                    el(PanelBody, { title: __('Settings', 'mlcm') },
                        el(SelectControl, {
                            label: __('Layout', 'mlcm'),
                            value: attributes.layout,
                            options: [
                                { label: __('Vertical', 'mlcm'), value: 'vertical' },
                                { label: __('Horizontal', 'mlcm'), value: 'horizontal' }
                            ],
                            onChange: (newLayout) => setAttributes({ layout: newLayout })
                        }),
                        el(RangeControl, {
                            label: __('Initial Levels', 'mlcm'),
                            value: attributes.levels,
                            onChange: (newLevels) => setAttributes({ levels: newLevels }),
                            min: 1,
                            max: 5
                        }),
                        el(ToggleControl, {
                            label: __('Use Modal Window', 'mlcm'),
                            checked: attributes.modal,
                            onChange: (modal) => setAttributes({ modal: modal })
                        })
                    )
                ),
                // Предварительный просмотр блока в редакторе
                el('div', { className: 'mlcm-block-preview' },
                    el('h3', null, __('Category Menu Preview', 'mlcm')),
                    el('p', null, __('Layout:', 'mlcm') + ' ' + attributes.layout),
                    el('p', null, __('Visible Levels:', 'mlcm') + ' ' + attributes.levels),
                    el('p', null, __('Modal Window:', 'mlcm') + ' ' + (attributes.modal ? __('Yes', 'mlcm') : __('No', 'mlcm')))
                )
            ];
        },

        save: function() {
            return null; // Динамический блок
        }
    });
})(window.wp);