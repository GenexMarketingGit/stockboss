(function (wp) {
    const { __, sprintf } = wp.i18n;
    const { registerBlockType, createBlock } = wp.blocks;
    const { useState, Fragment, useEffect } = wp.element;
    const { addFilter } = wp.hooks;
    const { createHigherOrderComponent } = wp.compose;
    const { MediaUpload, MediaUploadCheck, useBlockProps, InspectorControls } = wp.blockEditor;
    const { TextareaControl, Button, Notice, Spinner, PanelBody, ToggleControl } = wp.components;
    const { useSelect } = wp.data;
    const apiFetch = wp.apiFetch;

    const config = window.StockbossConfig || {
        restPath: '/stockboss/v1/generate-image',
    };

    function getSuccessMessage(response) {
        if (response && response.fallbackUsed && response.modelUsed) {
            return sprintf(
                __('Image generated using fallback model: %s.', 'stockboss'),
                response.modelUsed
            );
        }

        return __('Image generated and added to Media Library.', 'stockboss');
    }

    function requestGeneration(data) {
        return apiFetch({
            path: config.restPath,
            method: 'POST',
            data: data,
        });
    }

    function useReferencePreview(id) {
        return useSelect(
            (select) => {
                if (!id) {
                    return null;
                }

                const media = select('core').getMedia(id);
                if (!media) {
                    return null;
                }

                const mediaDetails = media.media_details || {};
                const sizes = mediaDetails.sizes || {};
                const medium = sizes.medium || {};
                const thumbnail = sizes.thumbnail || {};

                return {
                    id: media.id,
                    url: medium.source_url || thumbnail.source_url || media.source_url,
                    alt: media.alt_text || '',
                };
            },
            [id]
        );
    }

    registerBlockType('stockboss/image-generator', {
        apiVersion: 3,
        title: __('Stockboss Image', 'stockboss'),
        icon: 'format-image',
        category: 'media',
        description: __('Generate stock-standard images with a simple prompt-first flow.', 'stockboss'),
        attributes: {
            imageId: { type: 'number' },
            imageUrl: { type: 'string', default: '' },
            alt: { type: 'string', default: '' },
            prompt: { type: 'string', default: '' },
            useCustomSystemPrompt: { type: 'boolean', default: false },
            systemPrompt: { type: 'string', default: '' },
            referenceImageIds: { type: 'array', default: [] },
        },
        supports: {
            html: false,
        },
        edit: function (props) {
            const { attributes, setAttributes } = props;
            const [isGenerating, setIsGenerating] = useState(false);
            const [error, setError] = useState('');
            const [isReferenceOpen, setIsReferenceOpen] = useState(false);
            const [isOverrideOpen, setIsOverrideOpen] = useState(!!attributes.useCustomSystemPrompt);

            const blockProps = useBlockProps({ className: 'stockboss-generator-block' });
            const referenceImageId = (attributes.referenceImageIds || [])[0] || null;
            const referencePreview = useReferencePreview(referenceImageId);

            useEffect(
                function () {
                    if (attributes.useCustomSystemPrompt && !isOverrideOpen) {
                        setIsOverrideOpen(true);
                    }
                },
                [attributes.useCustomSystemPrompt]
            );

            function generateImage() {
                setError('');

                if (!attributes.prompt || !attributes.prompt.trim()) {
                    setError(__('Please enter a prompt first.', 'stockboss'));
                    return;
                }

                setIsGenerating(true);

                requestGeneration({
                    prompt: attributes.prompt,
                    useCustomSystemPrompt: !!attributes.useCustomSystemPrompt,
                    systemPrompt: attributes.systemPrompt || '',
                    referenceImageIds: referenceImageId ? [referenceImageId] : [],
                })
                    .then(function (response) {
                        if (!response || !response.url) {
                            throw new Error(__('No image URL returned.', 'stockboss'));
                        }

                        wp.data.dispatch('core/notices').createNotice('success', getSuccessMessage(response), {
                            type: 'snackbar',
                        });

                        wp.data.dispatch('core/block-editor').replaceBlocks(
                            props.clientId,
                            createBlock('core/image', {
                                id: response.attachmentId,
                                url: response.url,
                                alt: attributes.prompt || '',
                            })
                        );
                    })
                    .catch(function (requestError) {
                        const fallbackMessage = __('Image generation failed. Check API key and provider availability.', 'stockboss');
                        const message = requestError && requestError.message ? requestError.message : fallbackMessage;
                        setError(message);
                    })
                    .finally(function () {
                        setIsGenerating(false);
                    });
            }

            function onSelectReference(selection) {
                const id = selection && selection.id ? selection.id : null;
                setAttributes({ referenceImageIds: id ? [id] : [] });
            }

            function toggleOverride() {
                const nextOpen = !isOverrideOpen;
                setIsOverrideOpen(nextOpen);
                setAttributes({ useCustomSystemPrompt: nextOpen });
            }

            return wp.element.createElement(
                Fragment,
                null,
                wp.element.createElement(
                    'div',
                    blockProps,
                    wp.element.createElement(
                        'div',
                        { className: 'stockboss-shell stockboss-hover-item' },
                        wp.element.createElement(
                            'div',
                            { className: 'stockboss-shell-header' },
                            wp.element.createElement('p', { className: 'stockboss-kicker' }, __('Stockboss', 'stockboss')),
                            wp.element.createElement('h3', { className: 'stockboss-title' }, __('Describe Your Image', 'stockboss')),
                            wp.element.createElement(
                                'p',
                                { className: 'stockboss-subtitle' },
                                __('Everything else comes from your Stockboss settings. Use icons for optional controls.', 'stockboss')
                            )
                        ),
                        wp.element.createElement(
                            'div',
                            { className: 'stockboss-hover-item stockboss-prompt-wrap' },
                            wp.element.createElement(TextareaControl, {
                                __nextHasNoMarginBottom: true,
                                label: __('Prompt', 'stockboss'),
                                help: __('Write the image you want.', 'stockboss'),
                                value: attributes.prompt || '',
                                rows: 5,
                                onChange: function (value) {
                                    setAttributes({ prompt: value });
                                },
                            })
                        ),
                        wp.element.createElement(
                            'div',
                            { className: 'stockboss-icon-row' },
                            wp.element.createElement(Button, {
                                icon: 'format-image',
                                label: __('Reference image', 'stockboss'),
                                className:
                                    'stockboss-icon-button stockboss-hover-item' +
                                    (isReferenceOpen ? ' is-active' : ''),
                                onClick: function () {
                                    setIsReferenceOpen(!isReferenceOpen);
                                },
                            }),
                            wp.element.createElement(Button, {
                                icon: 'admin-generic',
                                label: __('Override system prompt', 'stockboss'),
                                className:
                                    'stockboss-icon-button stockboss-hover-item' +
                                    (isOverrideOpen ? ' is-active' : ''),
                                onClick: toggleOverride,
                            })
                        ),
                        wp.element.createElement(
                            'div',
                            {
                                className:
                                    'stockboss-collapse stockboss-hover-item' +
                                    (isReferenceOpen ? ' is-open' : ''),
                            },
                            wp.element.createElement(
                                'div',
                                { className: 'stockboss-collapse-inner' },
                                wp.element.createElement(
                                    'p',
                                    { className: 'stockboss-collapse-title' },
                                    __('Optional Reference Image', 'stockboss')
                                ),
                                wp.element.createElement(
                                    MediaUploadCheck,
                                    null,
                                    wp.element.createElement(MediaUpload, {
                                        onSelect: onSelectReference,
                                        allowedTypes: ['image'],
                                        multiple: false,
                                        value: referenceImageId,
                                        render: function (_ref) {
                                            const open = _ref.open;
                                            return wp.element.createElement(
                                                'div',
                                                { className: 'stockboss-reference-actions' },
                                                wp.element.createElement(
                                                    Button,
                                                    {
                                                        variant: 'secondary',
                                                        className: 'stockboss-hover-item',
                                                        onClick: open,
                                                    },
                                                    referenceImageId ? __('Replace Image', 'stockboss') : __('Select Image', 'stockboss')
                                                ),
                                                wp.element.createElement(
                                                    Button,
                                                    {
                                                        variant: 'tertiary',
                                                        className: 'stockboss-hover-item',
                                                        disabled: !referenceImageId,
                                                        onClick: function () {
                                                            setAttributes({ referenceImageIds: [] });
                                                        },
                                                    },
                                                    __('Clear', 'stockboss')
                                                )
                                            );
                                        },
                                    })
                                ),
                                referencePreview
                                    ? wp.element.createElement(
                                          'div',
                                          { className: 'stockboss-reference-preview' },
                                          wp.element.createElement('img', {
                                              src: referencePreview.url,
                                              alt: referencePreview.alt,
                                          })
                                      )
                                    : null
                            )
                        ),
                        wp.element.createElement(
                            'div',
                            {
                                className:
                                    'stockboss-collapse stockboss-hover-item' +
                                    (isOverrideOpen ? ' is-open' : ''),
                            },
                            wp.element.createElement(
                                'div',
                                { className: 'stockboss-collapse-inner' },
                                wp.element.createElement(
                                    'p',
                                    { className: 'stockboss-collapse-title' },
                                    __('Optional Override Prompt', 'stockboss')
                                ),
                                wp.element.createElement(TextareaControl, {
                                    __nextHasNoMarginBottom: true,
                                    label: __('Custom System Prompt', 'stockboss'),
                                    value: attributes.systemPrompt || '',
                                    rows: 4,
                                    onChange: function (value) {
                                        setAttributes({ systemPrompt: value });
                                    },
                                })
                            )
                        ),
                        error
                            ? wp.element.createElement(Notice, {
                                  status: 'error',
                                  isDismissible: false,
                                  children: error,
                              })
                            : null,
                        wp.element.createElement(
                            'div',
                            { className: 'stockboss-generate-row' },
                            wp.element.createElement(
                                Button,
                                {
                                    variant: 'primary',
                                    className: 'stockboss-generate-button stockboss-hover-item',
                                    onClick: generateImage,
                                    disabled: isGenerating,
                                },
                                isGenerating
                                    ? wp.element.createElement(
                                          Fragment,
                                          null,
                                          wp.element.createElement(Spinner, null),
                                          wp.element.createElement('span', null, __('Generating...', 'stockboss'))
                                      )
                                    : __('Generate Image', 'stockboss')
                            )
                        )
                    )
                )
            );
        },
        save: function () {
            return null;
        },
    });

    const withStockbossImageIteration = createHigherOrderComponent(
        function (BlockEdit) {
            return function (props) {
                if (props.name !== 'core/image') {
                    return wp.element.createElement(BlockEdit, props);
                }

                const imageId = props.attributes && props.attributes.id ? props.attributes.id : 0;
                const imageUrl = props.attributes && props.attributes.url ? props.attributes.url : '';

                const [iterationPrompt, setIterationPrompt] = useState('');
                const [useCustomSystemPrompt, setUseCustomSystemPrompt] = useState(false);
                const [systemPrompt, setSystemPrompt] = useState('');
                const [isIterating, setIsIterating] = useState(false);
                const [error, setError] = useState('');

                function iterateImage() {
                    setError('');

                    if (!iterationPrompt.trim()) {
                        setError(__('Enter iteration instructions first.', 'stockboss'));
                        return;
                    }

                    setIsIterating(true);

                    requestGeneration({
                        prompt: iterationPrompt,
                        useCustomSystemPrompt: useCustomSystemPrompt,
                        systemPrompt: systemPrompt,
                        referenceImageIds: imageId ? [imageId] : [],
                    })
                        .then(function (response) {
                            if (!response || !response.url) {
                                throw new Error(__('No image URL returned.', 'stockboss'));
                            }

                            props.setAttributes({
                                id: response.attachmentId,
                                url: response.url,
                                alt: iterationPrompt || props.attributes.alt || '',
                            });

                            wp.data.dispatch('core/notices').createNotice('success', getSuccessMessage(response), {
                                type: 'snackbar',
                            });
                        })
                        .catch(function (requestError) {
                            const fallbackMessage = __('Iteration failed. Check API key and provider availability.', 'stockboss');
                            const message = requestError && requestError.message ? requestError.message : fallbackMessage;
                            setError(message);
                        })
                        .finally(function () {
                            setIsIterating(false);
                        });
                }

                return wp.element.createElement(
                    Fragment,
                    null,
                    wp.element.createElement(BlockEdit, props),
                    props.isSelected
                        ? wp.element.createElement(
                              InspectorControls,
                              null,
                              wp.element.createElement(
                                  PanelBody,
                                  {
                                      title: __('Stockboss Iterate', 'stockboss'),
                                      initialOpen: false,
                                  },
                                  wp.element.createElement(
                                      'div',
                                      { className: 'stockboss-iterate-panel' },
                                      !imageUrl
                                          ? wp.element.createElement(Notice, {
                                                status: 'warning',
                                                isDismissible: false,
                                                children: __('This image block has no image URL yet.', 'stockboss'),
                                            })
                                          : null,
                                      !imageId && imageUrl
                                          ? wp.element.createElement(Notice, {
                                                status: 'info',
                                                isDismissible: false,
                                                children: __('No media ID found. Iteration will run without using the current image as a reference.', 'stockboss'),
                                            })
                                          : null,
                                      wp.element.createElement(
                                          'div',
                                          { className: 'stockboss-iterate-card' },
                                          wp.element.createElement(TextareaControl, {
                                              __nextHasNoMarginBottom: true,
                                              label: __('Edit Instructions', 'stockboss'),
                                              help: __('Describe the changes you want for this image.', 'stockboss'),
                                              value: iterationPrompt,
                                              rows: 4,
                                              onChange: setIterationPrompt,
                                          })
                                      ),
                                      wp.element.createElement(
                                          'div',
                                          { className: 'stockboss-iterate-card' },
                                          wp.element.createElement(ToggleControl, {
                                              __nextHasNoMarginBottom: true,
                                              label: __('Override System Prompt', 'stockboss'),
                                              checked: useCustomSystemPrompt,
                                              onChange: setUseCustomSystemPrompt,
                                          }),
                                          useCustomSystemPrompt
                                              ? wp.element.createElement(TextareaControl, {
                                                    __nextHasNoMarginBottom: true,
                                                    label: __('Custom System Prompt', 'stockboss'),
                                                    value: systemPrompt,
                                                    rows: 3,
                                                    onChange: setSystemPrompt,
                                                })
                                              : null
                                      ),
                                      error
                                          ? wp.element.createElement(Notice, {
                                                status: 'error',
                                                isDismissible: false,
                                                children: error,
                                            })
                                          : null,
                                      wp.element.createElement(
                                          Button,
                                          {
                                              variant: 'primary',
                                              className: 'stockboss-hover-item',
                                              onClick: iterateImage,
                                              disabled: isIterating || !imageUrl,
                                          },
                                          isIterating
                                              ? wp.element.createElement(
                                                    Fragment,
                                                    null,
                                                    wp.element.createElement(Spinner, null),
                                                    wp.element.createElement('span', null, __('Iterating...', 'stockboss'))
                                                )
                                              : __('Iterate Image', 'stockboss')
                                      )
                                  )
                              )
                          )
                        : null
                );
            };
        },
        'withStockbossImageIteration'
    );

    addFilter('editor.BlockEdit', 'stockboss/with-image-iteration', withStockbossImageIteration);
})(window.wp);
