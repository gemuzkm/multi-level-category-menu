(function(wp) {
    const { registerBlockType } = wp.blocks;
    const { InspectorControls } = wp.blockEditor;
    const { PanelBody, SelectControl, RangeControl } = wp.components;
    const { __ } = wp.i18n;

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

        edit: ({ attributes, setAttributes }) => {
            return (
                
                    <InspectorControls>
                        <PanelBody title={__('Settings', 'mlcm')}>
                            <SelectControl
                                label={__('Layout', 'mlcm')}
                                value={attributes.layout}
                                options={[
                                    { label: __('Vertical', 'mlcm'), value: 'vertical' },
                                    { label: __('Horizontal', 'mlcm'), value: 'horizontal' }
                                ]}
                                onChange={(newLayout) => setAttributes({ layout: newLayout })}
                            />
                            
                            <RangeControl
                                label={__('Initial Levels', 'mlcm')}
                                value={attributes.levels}
                                onChange={(newLevels) => setAttributes({ levels: newLevels })}
                                min={1}
                                max={5}
                            />
                        </PanelBody>
                    </InspectorControls>
                    
                    <div className="mlcm-block-preview">
                        <h3>{__('Category Menu Preview', 'mlcm')}</h3>
                        <p>{__('Layout:', 'mlcm')} {attributes.layout}</p>
                        <p>{__('Visible Levels:', 'mlcm')} {attributes.levels}</p>
                    </div>
                </>
            );
        },

        save: () => null
    });
})(window.wp);