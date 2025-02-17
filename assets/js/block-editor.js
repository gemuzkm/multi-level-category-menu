(function(wp) {
    const { __ } = wp.i18n;
    const { registerBlockType } = wp.blocks;
    const { InspectorControls } = wp.blockEditor;
    const { 
        PanelBody, 
        SelectControl, 
        RangeControl, 
        ToggleControl, 
        ColorPalette, 
        FontSizePicker,
        TextControl
    } = wp.components;
    const { createElement: el } = wp.element;

    registerBlockType('mlcm/menu-block', {
        title: __('Category Menu', 'mlcm'),
        icon: 'menu',
        category: 'widgets',
        attributes: {
            layout: { type: 'string', default: 'vertical' },
            levels: { type: 'number', default: 3 },
            showButton: { type: 'boolean', default: true },
            bgColor: { type: 'string', default: '#ffffff' },
            textColor: { type: 'string', default: '#333333' },
            fontSize: { type: 'number', default: 16 },
            spacing: { type: 'number', default: 20 },
            alignment: { type: 'string', default: 'left' },
            buttonLabel: { type: 'string', default: 'Go' },
            buttonWidth: { type: 'number', default: 100 },
            buttonWidthUnit: { type: 'string', default: 'px' },
            buttonPosition: { type: 'string', default: 'after' },
            buttonBgColor: { type: 'string', default: '#0073aa' },
            buttonTextColor: { type: 'string', default: '#ffffff' },
            buttonBorderRadius: { type: 'number', default: 4 },
            buttonBorderWidth: { type: 'number', default: 0 },
            buttonBorderColor: { type: 'string', default: '#0073aa' },
            fontSizeUnit: { type: 'string', default: 'px' },
            gap: { type: 'number', default: 20 }
        },
        edit: function(props) {
            const { attributes, setAttributes } = props;
            const { 
                layout, 
                levels, 
                showButton, 
                bgColor, 
                textColor, 
                fontSize,
                spacing,
                alignment,
                buttonLabel,
                buttonWidth,
                buttonWidthUnit,
                buttonPosition,
                buttonBgColor,
                buttonTextColor,
                buttonBorderRadius,
                buttonBorderWidth,
                buttonBorderColor,
                fontSizeUnit,
                gap
            } = attributes;

            return el('div', null,
                el(InspectorControls, null,
                    el(PanelBody, { title: __('Main Settings', 'mlcm') },
                        el(SelectControl, {
                            __nextHasNoMarginBottom: true,
                            label: __('Layout', 'mlcm'),
                            value: layout,
                            options: [
                                { label: __('Vertical', 'mlcm'), value: 'vertical' },
                                { label: __('Horizontal', 'mlcm'), value: 'horizontal' }
                            ],
                            onChange: (value) => setAttributes({ layout: value })
                        }),
                        el(RangeControl, {
                            __nextHasNoMarginBottom: true,
                            label: __('Initial Levels', 'mlcm'),
                            value: levels,
                            onChange: (value) => setAttributes({ levels: value }),
                            min: 1,
                            max: 5
                        }),
                        el(ToggleControl, {
                            label: __('Show "Go" Button', 'mlcm'),
                            checked: showButton,
                            onChange: (value) => setAttributes({ showButton: value })
                        })
                    ),
                    el(PanelBody, { title: __('Layout Settings', 'mlcm'), initialOpen: false },
                        el(SelectControl, {
                            label: __('Alignment', 'mlcm'),
                            value: alignment,
                            options: [
                                { label: __('Left', 'mlcm'), value: 'left' },
                                { label: __('Center', 'mlcm'), value: 'center' },
                                { label: __('Right', 'mlcm'), value: 'right' }
                            ],
                            onChange: (value) => setAttributes({ alignment: value })
                        }),
                        el(SelectControl, {
                            label: __('Button Position', 'mlcm'),
                            value: buttonPosition,
                            options: [
                                { label: __('After Menu', 'mlcm'), value: 'after' },
                                { label: __('Before Menu', 'mlcm'), value: 'before' }
                            ],
                            onChange: (value) => setAttributes({ buttonPosition: value })
                        }),
                        el(RangeControl, {
                            label: __('Gap Between Elements', 'mlcm'),
                            value: gap,
                            min: 0,
                            max: 100,
                            onChange: (value) => setAttributes({ gap: value })
                        })
                    ),
                    el(PanelBody, { title: __('Button Settings', 'mlcm'), initialOpen: false },
                        el(TextControl, {
                            label: __('Button Text', 'mlcm'),
                            value: buttonLabel,
                            onChange: (value) => setAttributes({ buttonLabel: value })
                        }),
                        el('div', { className: 'components-base-control' },
                            el('div', { 
                                style: { 
                                    display: 'flex', 
                                    gap: '8px',
                                    alignItems: 'center' 
                                }
                            },
                                el(TextControl, {
                                    type: 'number',
                                    label: __('Button Width', 'mlcm'),
                                    value: buttonWidth,
                                    onChange: (value) => setAttributes({ buttonWidth: value })
                                }),
                                el(SelectControl, {
                                    value: buttonWidthUnit,
                                    options: [
                                        { label: 'px', value: 'px' },
                                        { label: '%', value: '%' }
                                    ],
                                    onChange: (value) => setAttributes({ buttonWidthUnit: value })
                                })
                            )
                        ),
                        el(ColorPalette, {
                            label: __('Background Color', 'mlcm'),
                            colors: wp.data.select('core/block-editor').getSettings().colors,
                            value: buttonBgColor,
                            onChange: (value) => setAttributes({ buttonBgColor: value })
                        }),
                        el(ColorPalette, {
                            label: __('Text Color', 'mlcm'),
                            colors: wp.data.select('core/block-editor').getSettings().colors,
                            value: buttonTextColor,
                            onChange: (value) => setAttributes({ buttonTextColor: value })
                        }),
                        el(RangeControl, {
                            label: __('Border Radius', 'mlcm'),
                            value: buttonBorderRadius,
                            min: 0,
                            max: 50,
                            onChange: (value) => setAttributes({ buttonBorderRadius: value })
                        }),
                        el(RangeControl, {
                            label: __('Border Width', 'mlcm'),
                            value: buttonBorderWidth,
                            min: 0,
                            max: 10,
                            onChange: (value) => setAttributes({ buttonBorderWidth: value })
                        }),
                        el(ColorPalette, {
                            label: __('Border Color', 'mlcm'),
                            colors: wp.data.select('core/block-editor').getSettings().colors,
                            value: buttonBorderColor,
                            onChange: (value) => setAttributes({ buttonBorderColor: value })
                        })
                    ),
                    el(PanelBody, { title: __('Typography', 'mlcm'), initialOpen: false },
                        el('div', { className: 'components-base-control' },
                            el('div', { 
                                style: { 
                                    display: 'flex', 
                                    gap: '8px',
                                    alignItems: 'center' 
                                }
                            },
                                el(TextControl, {
                                    type: 'number',
                                    label: __('Font Size', 'mlcm'),
                                    value: fontSize,
                                    onChange: (value) => setAttributes({ fontSize: value })
                                }),
                                el(SelectControl, {
                                    value: fontSizeUnit,
                                    options: [
                                        { label: 'px', value: 'px' },
                                        { label: 'rem', value: 'rem' }
                                    ],
                                    onChange: (value) => setAttributes({ fontSizeUnit: value })
                                })
                            )
                        )
                    )
                ),
                el('div', { 
                    className: 'mlcm-block-preview',
                    style: { 
                        backgroundColor: bgColor,
                        color: textColor,
                        fontSize: fontSize + fontSizeUnit,
                        padding: spacing + 'px',
                        gap: gap + 'px',
                        justifyContent: alignment === 'left' ? 'flex-start' : 
                                      alignment === 'center' ? 'center' : 'flex-end'
                    }
                },
                    el('h3', null, __('Category Menu Preview', 'mlcm')),
                    el('div', { 
                        style: { 
                            display: 'flex', 
                            gap: gap + 'px', 
                            flexDirection: layout === 'vertical' ? 'column' : 'row' 
                        }
                    },
                        Array.from({ length: levels }).map((_, i) => 
                            el('select', { 
                                key: i,
                                style: { 
                                    width: '250px',
                                    backgroundColor: '#fff',
                                    color: '#333',
                                    fontSize: fontSize + fontSizeUnit
                                }
                            },
                                el('option', { value: '-1' }, __('Category', 'mlcm') + ' ' + (i + 1))
                            )
                        ),
                        showButton && el('button', { 
                            style: { 
                                backgroundColor: buttonBgColor,
                                color: buttonTextColor,
                                border: buttonBorderWidth + 'px solid ' + buttonBorderColor,
                                borderRadius: buttonBorderRadius + 'px',
                                width: buttonWidth + buttonWidthUnit,
                                padding: '10px 20px',
                                fontSize: fontSize + fontSizeUnit
                            }
                        }, buttonLabel)
                    )
                )
            );
        },
        save: function() {
            return null;
        }
    });
})(window.wp);