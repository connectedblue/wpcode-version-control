# **📦 WPCode Version Control**

**A lightweight, robust version control system for the [WPCode](https://wordpress.org/plugins/insert-headers-and-footers/) plugin.**

If you manage a large number of snippets (PHP, JS, CSS) using WPCode, you know that one bad edit can break your site. **WPCode Version Control** adds a safety layer, allowing you to take instant snapshots of your entire snippet library and restore individual snippets with surgical precision.

## **✨ Features**

* **📸 Instant Snapshots:** Backup your entire library (code, logic, settings) in one click.  
* **🔍 Smart Restore:** Don't blindly overwrite\! The "Inspect" view compares your backup against the live database:  
  * **Identical:** ⚪ Skipped (safe).  
  * **Changed:** 🔵 Overwrites the current live snippet.  
  * **Deleted:** 🔴 Re-creates the snippet if it was missing.  
* **📂 JSON Storage:** Snapshots are stored as JSON files in your /uploads directory, keeping your database light.  
* **🗑️ Safety Net:** Deleted versions go to a "Trash" can before permanent deletion.  
* **⏲️ Auto-Cleanup:** Trashed versions are automatically permanently deleted after 30 days (via WP Cron).  
* **⚡ Bulk Actions:** Delete or Restore multiple versions at once.  
* **🛡️ Secure:** Backup directory is protected from public access via .htaccess.

## **🚀 Installation**

### **Option 1: The Easy Way (Zip)**

1. Download this repository as [a ZIP file](https://github.com/connectedblue/wpcode-version-control/archive/refs/heads/main.zip).  
2. Go to your WordPress Admin: **Plugins \> Add New \> Upload Plugin**.  
3. Upload the zip and activate.

### **Option 2: The Developer Way (Manual)**

1. Create a folder in your plugins directory: /wp-content/plugins/wpcode-version-control/.  
2. Copy the wpcode-version-control.php file into that folder.  
3. Activate **WPCode Version Control** from the Plugins menu.

**Note:** This plugin requires the **WPCode** plugin to be installed and active.

## **📖 How to Use**

Once installed, you will see a new menu item in your dashboard:

👉 **Code Snippets \> Version Control**

### **1\. Creating a Snapshot 📸**

1. Enter a description for your version (e.g., *"Before Header Redesign"*).  
2. Click **Create Snapshot**.  
3. A JSON file containing all your snippets is saved to your server.

### **2\. Restoring Snippets ⏪**

1. Locate the version you want to restore from the list.  
2. Click the **Inspect & Restore** button.  
3. You will see a table listing every snippet in that backup:  
   * **Check the boxes** for the specific snippets you want to fix.  
   * Click **Restore Selected**.  
   * *The plugin will intelligently update existing IDs or create new snippets if they were deleted.*

### **3\. Managing Versions 🗑️**

* **Trash:** Click "Delete" on a version or select multiple and use "Move Selected to Trash".  
* **Permanent Delete:** Go to the **Trash** view. Here you can "Delete Permanently" (removes the file) or let the auto-cleaner handle it after 30 days.

## **⚙️ Technical Details**

* **Storage Location:** /wp-content/uploads/wpcode-versions/  
* **Database Footprint:** Uses a single option wpcode\_vc\_index to track version metadata. It does not create custom tables.  
* **Cron Job:** wpcode\_vc\_daily\_cleanup runs once daily to purge trash older than 30 days.

## **⚠️ Disclaimer**

This plugin is a third-party add-on and is not officially affiliated with WPCode (WPCode is a trademark of WPCode). Use this plugin at your own risk. Always ensure you have a full site backup before performing bulk restoration operations on production sites.

**Enjoy coding safely\!** 🚀
