# WoW Raid Progress Tracker - WordPress Plugin

## Version 5.0.0 - Complete Rewrite

A WordPress plugin to display World of Warcraft raid progress from Raider.io API with Blizzard achievement icons.

## 📁 File Structure

```
wow-raid-progress/
├── wow-raid-progress.php           # Main plugin file
├── README.md                        # This file
├── LICENSE                          # GPL v2 license
├── includes/                        # PHP class files
│   ├── class-wow-raid-progress.php         # Main plugin class
│   ├── class-wow-raid-progress-admin.php   # Admin settings class
│   ├── class-wow-raid-progress-api.php     # API handler class
│   └── class-wow-raid-progress-widget.php  # Widget/shortcode class
├── assets/                          # Frontend assets
│   ├── css/
│   │   ├── wow-raid-progress.css   # Frontend styles
│   │   └── admin.css                # Admin panel styles
│   └── js/
│       ├── wow-raid-progress.js    # Frontend JavaScript
│       └── admin.js                 # Admin panel JavaScript
└── languages/                       # Translation files
    ├── wow-raid-progress.pot       # Translation template
    └── wow-raid-progress-de_DE.mo  # German translation (example)
```

## 🚀 Key Improvements

### 1. ✅ CSS as External Assets
- All styles moved to external CSS files
- No inline styles except for dynamic width values
- Proper asset enqueueing with version control

### 2. ✅ Comprehensive Error Handling
- Graceful handling of API failures
- User-friendly error messages
- No site-breaking errors
- Detailed error logging for debugging
- Fallback mechanisms for missing data

### 3. ✅ Complete Shortcode Documentation
- Full documentation in admin panel
- Interactive examples
- All parameters explained
- Copy-paste ready examples

### 4. ✅ Automatic Raid Loading
- Raids loaded dynamically based on expansion
- Cached for performance
- Dropdown selection in settings
- Refresh button to update cache

### 5. ✅ Full Internationalization
- Text domain: `wow-raid-progress`
- All strings translatable
- POT file included
- Translation-ready architecture

### 6. ✅ Default Settings System
- All shortcode parameters have defaults
- Settings configurable in admin panel
- Shortcode attributes override defaults
- Persistent settings storage

### 7. ✅ Clean CSS Architecture
- All styles in external files
- No inline CSS (except dynamic values)
- Responsive design included
- Print styles included

### 8. ✅ Multiple Guild Support
- Loop through multiple guild IDs
- Display each guild separately
- Comma-separated guild IDs
- Individual guild progress tracking

### 9. ✅ WordPress Best Practices
- Proper nonce verification
- Capability checks
- Data sanitization and validation
- Secure database queries
- Proper escaping of output
- No direct file access

### 10. ✅ Modern Admin Interface
- Tabbed interface
- Grouped settings sections
- Visual feedback for actions
- Loading states
- Success/error messages
- Matches WordPress admin design

## 📋 Installation

1. **Upload Plugin**
   - Upload the `wow-raid-progress` folder to `/wp-content/plugins/`
   - Or install via WordPress admin panel

2. **Activate Plugin**
   - Go to Plugins page in WordPress admin
   - Click "Activate" for WoW Raid Progress Tracker

3. **Configure Settings**
   - Navigate to Settings → WoW Raid Progress
   - Enter your Raider.io API key
   - Configure default settings
   - Optional: Add Blizzard API credentials for icons

## ⚙️ Configuration

### Required Settings

1. **Raider.io API Key**
   - Get from: https://raider.io/api
   - Required for fetching raid data

### Optional Settings

1. **Blizzard API Credentials**
   - Get from: https://develop.battle.net
   - Required only for boss icons
   - Client ID and Client Secret needed

2. **Default Guild Settings**
   - Guild IDs (comma-separated)
   - Default region and realm
   - Default raid and difficulty

## 📝 Shortcode Usage

### Basic Usage
```
[wow_raid_progress]
```

### With Parameters
```
[wow_raid_progress raid="nerub-ar-palace" difficulty="mythic" guilds="12345,67890"]
```

### All Parameters

| Parameter | Description | Values | Default |
|-----------|-------------|--------|---------|
| `raid` | Raid slug | e.g., nerub-ar-palace | From settings |
| `difficulty` | Difficulty to show | highest, all, normal, heroic, mythic | From settings |
| `region` | Game region | us, eu, kr, tw | From settings |
| `realm` | Specific realm | Any valid realm name | From settings |
| `guilds` | Guild IDs | Comma-separated IDs | From settings |
| `cache` | Cache time in minutes | 0-1440 | From settings |
| `show_icons` | Display boss icons | true, false | From settings |
| `show_killed` | Show defeated bosses | true, false | From settings |
| `use_blizzard_icons` | Use Blizzard icons | true, false | From settings |
| `limit` | Max guilds to show | 1-100 | From settings |
| `page` | Pagination page | 0+ | 0 |

## 🔒 Security Features

- **Nonce Verification**: All AJAX requests verified
- **Capability Checks**: Admin actions require proper permissions
- **Data Sanitization**: All inputs sanitized
- **SQL Injection Prevention**: Prepared statements used
- **XSS Protection**: All output properly escaped
- **CSRF Protection**: WordPress nonce system utilized

## 🌐 Translation

### Creating Translations

1. Use the POT file in `/languages/` folder
2. Create translation with tools like Poedit
3. Save as `wow-raid-progress-{locale}.po` and `.mo`
4. Place in `/languages/` folder

### Available Text Domains
- Plugin Name: `wow-raid-progress`
- All strings use `__()` or `_e()` functions

## 🐛 Error Handling

### API Errors
- Connection failures handled gracefully
- Invalid API keys detected and reported
- Rate limiting respected
- Timeout handling implemented

### Data Errors
- Missing raid data handled
- Invalid guild IDs caught
- Wrong realm names managed
- Empty responses handled

### User Feedback
- Clear error messages displayed
- Suggestions for fixes provided
- No technical jargon in user messages
- Admin error logs for debugging

## 🎨 Styling

### CSS Classes Structure
```css
.wow-raid-progress-container     /* Main container */
.wow-raid-section                /* Each difficulty section */
.wow-raid-header                 /* Section header */
.wow-raid-title                  /* Guild/raid name */
.wow-difficulty-badge            /* Difficulty indicator */
.wow-progress-bar                /* Progress bar container */
.wow-progress-fill               /* Progress bar fill */
.wow-boss-list                   /* Boss list container */
.wow-boss-item                   /* Individual boss */
.wow-boss-item.defeated          /* Defeated boss */
.wow-boss-item.in-progress       /* Boss being attempted */
.wow-boss-item.not-started       /* Not attempted boss */
```

### Customization
- Override styles in your theme's CSS
- Use specific selectors for precedence
- All colors use CSS variables (future update)

## 📊 Performance

### Caching Strategy
- API responses cached (configurable duration)
- Static raid data cached for 24 hours
- Blizzard tokens cached until expiry
- Boss icons cached permanently

### Optimization
- Lazy loading for images
- Minimal database queries
- Efficient AJAX requests
- CDN-ready assets

## 🔧 Troubleshooting

### Common Issues

1. **No data showing**
   - Check API key is valid
   - Verify guild IDs are correct
   - Ensure region/realm match

2. **Icons not loading**
   - Verify Blizzard API credentials
   - Click "Import Icons" in settings
   - Check media library permissions

3. **Cache not clearing**
   - Use "Clear Cache" button
   - Check database permissions
   - Verify transient storage works

## 📚 API Documentation

### Raider.io API
- Documentation: https://raider.io/api
- Endpoints used:
  - `/api/v1/raiding/raid-rankings`
  - `/api/v1/raiding/static-data`

### Blizzard API
- Documentation: https://develop.battle.net/documentation
- Endpoints used:
  - Achievement categories
  - Achievement media

## 🤝 Support

For issues or questions:
1. Check documentation above
2. Review error messages
3. Enable WordPress debug mode
4. Contact plugin support

## 📄 License

GPL v2 or later - See LICENSE file

## 🎯 Future Updates

- [ ] Widget support
- [ ] Gutenberg block
- [ ] More visual themes
- [ ] Boss kill timestamps
- [ ] Guild comparison view
- [ ] Export functionality
- [ ] Webhook notifications
- [ ] Mobile app integration

## 👥 Credits

- Raider.io for API access
- Blizzard Entertainment for game data
- WordPress community for best practices

---

**Version:** 5.0.0  
**Requires WordPress:** 5.0+  
**Requires PHP:** 7.2+  
**Tested up to:** WordPress 6.4