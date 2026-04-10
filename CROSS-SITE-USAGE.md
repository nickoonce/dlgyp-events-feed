# Publishing Events Across Multiple Websites

This plugin now supports displaying events from one central WordPress site on multiple other sites. There are three methods available:

## Method 1: Remote Shortcode (Easiest)

Use the `[clamp_events_remote]` shortcode on any site to display events from your main events site.

**On the main events site:**
- Install and activate this plugin
- Add events as usual

**On other sites:**
- Install and activate this plugin
- Use the shortcode:

```
[clamp_events_remote url="https://bastardos.dlgyp.org"]
```

### Shortcode Attributes:
- `url` - (Required) Full URL of the source website
- `limit` - Number of events to display (default: 10)
- `category` - Filter by category slug (chapter, bastardos, bobs, etc.)

### Examples:
```
[clamp_events_remote url="https://bastardos.dlgyp.org" limit="5"]

[clamp_events_remote url="https://bastardos.dlgyp.org" category="chapter"]

[clamp_events_remote url="https://bastardos.dlgyp.org" category="bastardos" limit="20"]
```

### Features:
- ✅ Automatic caching (15 minutes)
- ✅ Shows event title, date/time, location
- ✅ Includes "Subscribe to Calendar" link
- ✅ Same styling as local events
- ✅ Error handling for connection issues

---

## Method 2: JSON API (For Developers)

Fetch event data directly via REST API and build custom displays.

### Endpoint:
```
https://bastardos.dlgyp.org/wp-json/clamp-events/v1/events
```

### Parameters:
- `limit` - Number of events (default: 10)
- `category` - Filter by category slug

### Example Response:
```json
[
  {
    "id": 123,
    "title": "Monthly Chapter Meeting",
    "content": "Event description...",
    "permalink": "https://bastardos.dlgyp.org/event/meeting/",
    "start": "2025-12-15 18:00:00",
    "end": "2025-12-15 20:00:00",
    "start_formatted": "Sunday, Dec 15, 2025 at 6:00 pm",
    "end_formatted": "8:00 pm",
    "location": "Main Hall",
    "ics_url": "https://bastardos.dlgyp.org/wp-json/clamp-events/v1/feed?event_id=123"
  }
]
```

### Usage with JavaScript:
```javascript
fetch('https://bastardos.dlgyp.org/wp-json/clamp-events/v1/events?limit=5')
  .then(response => response.json())
  .then(events => {
    events.forEach(event => {
      console.log(event.title, event.start_formatted);
    });
  });
```

---

## Method 3: iCalendar Subscription

Users can subscribe to your calendar feed in their calendar apps (iOS, Google Calendar, Outlook, etc.).

### Feed URLs:

**All events:**
```
https://bastardos.dlgyp.org/wp-json/clamp-events/v1/feed
```

**By category:**
```
https://bastardos.dlgyp.org/wp-json/clamp-events/v1/feed?category=chapter
https://bastardos.dlgyp.org/wp-json/clamp-events/v1/feed?category=bastardos
https://bastardos.dlgyp.org/wp-json/clamp-events/v1/feed?category=bobs
```

**For webcal:// links (one-click subscribe):**
```
webcal://bastardos.dlgyp.org/wp-json/clamp-events/v1/feed
```

---

## Recommended Setup

1. **Main Events Site** (e.g., bastardos.dlgyp.org)
   - Install this plugin
   - Create and manage all events here
   - Use categories to organize events (chapter, bastardos, bobs)

2. **Secondary Sites** (e.g., chapter.dlgyp.org, bobs.dlgyp.org)
   - Install this plugin
   - Add shortcode to pages/posts:
     ```
     [clamp_events_remote url="https://bastardos.dlgyp.org" category="chapter"]
     ```

3. **Benefits:**
   - ✅ Manage events in ONE place
   - ✅ Automatically appear on all sites
   - ✅ Consistent formatting across sites
   - ✅ Built-in caching for performance
   - ✅ Users can subscribe via calendar apps

---

## Troubleshooting

**Events not showing:**
- Verify the source URL is correct and accessible
- Check that the plugin is installed on BOTH sites
- Clear WordPress cache (if using a caching plugin)
- Wait 15 minutes for cache to refresh

**Connection errors:**
- Ensure source site is online and accessible
- Check for firewall/security restrictions
- Verify SSL certificate is valid (if using HTTPS)

**Styling issues:**
- Both shortcodes use the same CSS classes
- Add custom CSS to your theme:
  ```css
  .clamp-events-list { /* container */ }
  .clamp-event-item { /* individual event */ }
  .clamp-event-title { /* event title */ }
  .clamp-event-datetime { /* date/time */ }
  .clamp-event-location { /* location */ }
  ```

---

## Cache Management

- Remote events are cached for **15 minutes**
- ICS feeds are cached for **15 minutes**
- Cache automatically clears when:
  - Events are published/updated/deleted
  - Categories are modified
  - Posts are trashed

To manually clear cache:
- Save/update any event on the main site
- Or wait 15 minutes for automatic refresh
