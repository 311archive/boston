<?php

namespace Drupal\bos311\EventSubscriber;

use Drupal\jsonapi\ResourceType\ResourceTypeBuildEvent;
use Drupal\jsonapi\ResourceType\ResourceTypeBuildEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class Bos311ApiEventSubscriber implements EventSubscriberInterface {

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents() {
        return [
            ResourceTypeBuildEvents::BUILD => [
                ['disableResourceType'],
            ],
        ];
    }

    /**
     * Disables any resource types that have been disabled by a test.
     *
     * @param \Drupal\jsonapi\ResourceType\ResourceTypeBuildEvent $event
     *   The build event.
     */
    public function disableResourceType(ResourceTypeBuildEvent $event) {
        if (in_array($event->getResourceTypeName(), $this->diabledResourceTypes, TRUE)) {
            $event->disableResourceType();
        }
    }

    private $diabledResourceTypes = [
        'action--action',
        'base_field_override--base_field_override',
        'block--block',
        'date_format--date_format',
        'entity_form_display--entity_form_display',
        'entity_form_mode--entity_form_mode',
        'entity_view_display--entity_view_display',
        'entity_view_mode--entity_view_mode',
        'field_config--field_config',
        'field_storage_config--field_storage_config',
        'file--file',
        'filter_format--filter_format',
        'image_style--image_style',
        'media_type--media_type',
        'menu--menu',
        'menu_link_content--menu_link_content',
        'metatag_defaults--metatag_defaults',
        'node--page',
        'node_type--node_type',
        'path_alias--path_alias',
        'self',
        'taxonomy_vocabulary--taxonomy_vocabulary',
        'user--user',
        'user_role--user_role',
        'view--view'
    ];
}