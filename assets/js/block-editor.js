(function(wp) {
    const { registerBlockType } = wp.blocks;
    const { InspectorControls } = wp.blockEditor;
    const { PanelBody, SelectControl, RangeControl } = wp.components;
    const { __ } = wp.i18n;
    const { createElement: el } = wp.element; // Use createElement for creating elements

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
            }
        },

        edit: function(props) {
            const { attributes, setAttributes } = props;

            return [
                // Settings panel in inspector
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
                        })
                    )
                ),
                // Block preview in editor
                el('div', { className: 'mlcm-block-preview' },
                    el('h3', null, __('Category Menu Preview', 'mlcm')),
                    el('p', null, __('Layout:', 'mlcm') + ' ' + attributes.layout),
                    el('p', null, __('Visible Levels:', 'mlcm') + ' ' + attributes.levels)
                )
            ];
        },

        save: function() {
            return null; // Dynamic block
        }
    });
})(window.wp);