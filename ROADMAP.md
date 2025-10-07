# MetaVox Roadmap

This document outlines the planned development of Metavox.  
The roadmap is a living document: priorities and scope may change based on feedback, contributions, and new insights.

👉 Have feedback or ideas? Open an [issue](https://github.com/nextcloud/metavox/issues).

---

### v1.0.2  --> Released 2025-09-03
- First public release of Metavox for Nextcloud

---

### v1.1 – Nextcloud Controls --> Released 2025-09-18
- Use native Nextcloud controls for better integration and UX  
- Improved consistency with the Nextcloud interface  
- Increased stability and maintainability  

---

### v1.1.2 – API --> Released 2025-09-21
- Using scripts to automatically fill metadata fields
- Creating new fields directly through the API
- Enabling advanced migration scenarios 

---
### v1.1.3 - Support for NextCloud 32 --> Released 2025-10-01
- Resolved an issue where values could not be selected in the multi-select component when spaces were present.
- Resolved an issue in the external API that prevented retrieving fields associated with a group folder.

---

## 📌 Planned

### Search on metadata 
- Search on the values of the MetaVox fields from the Nextcloud unified search
- Update the search index for modified (created, deleted, updated) metadata

### Retaining metadata on copy action 
- When a folder (with all folders and files in it) or file is copied, the metadata is also copied
- When a folder (with all folders and files in it) or file is deleted, the metadata is also deleted
- When a folder (with all folders and files in it) or file is restored, the metadata is also restored

---

## ⚙️ Advanced features

### Retention Policies
- Archive or delete data based on metadata  
- Configurable policies (e.g. time-based or type-based)  
- Administrative controls for compliance and data management  

### AI integration
- AI-powered metadata extraction (automatically generate metadata based on document contents)
  - Per document and per team folder

### More granular permissions
- Team folder owners can configure metadata fields for their own team folders

### Infinite Scrolling for Team Folders
- Add infinite scrolling to Team Folders to improve performance with large folder sets
  
---

## 💡 Future Ideas
*(Not scheduled yet, under consideration)*   
- Integration with Nextcloud Flow for automated actions  
- Reporting on usage and retention
- More advanced field types
- Implement support for domain-specific metadata standards (e.g., Dublin Core) to improve interoperability and semantic consistency across research and content management workflows.   


---

## 🌍 Long-Term Vision
*(Exploratory goals, subject to funding and partnerships)*  

### Integration with European 6G Initiatives
- Explore opportunities to align Metavox with European 6G research and innovation projects  
- Potential funding applications to support advanced metadata-driven voice archiving and compliance use cases  
- Position Metavox within next-generation telecommunications and cloud ecosystems  

