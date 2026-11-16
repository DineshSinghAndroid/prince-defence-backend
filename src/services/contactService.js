const { mongoose } = require("mongoose")
const Contact = require("../models/Contacts")
const ApiError = require("../utils/ApiError")



const escapeRegex = (value) => {
    return String(value).replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
};

const parseDateBoundary = (dateStr, isEnd = false) => {
    if (!dateStr || typeof dateStr !== "string") return null;
    const trimmed = dateStr.trim();
    if (!trimmed) return null;

    // Format: YYYY-MM (e.g. "2026-11")
    if (/^\d{4}-\d{2}$/.test(trimmed)) {
        const [yearStr, monthStr] = trimmed.split("-");
        const year = Number(yearStr);
        const month = Number(monthStr);
        if (isEnd) {
            const lastDay = new Date(year, month, 0).getDate();
            const d = new Date(`${trimmed}-${String(lastDay).padStart(2, "0")}T23:59:59.999+05:30`);
            return isNaN(d.getTime()) ? null : d;
        } else {
            const d = new Date(`${trimmed}-01T00:00:00.000+05:30`);
            return isNaN(d.getTime()) ? null : d;
        }
    }

    // Format: YYYY-MM-DD
    if (/^\d{4}-\d{2}-\d{2}$/.test(trimmed)) {
        const timePart = isEnd ? "T23:59:59.999+05:30" : "T00:00:00.000+05:30";
        const d = new Date(`${trimmed}${timePart}`);
        return isNaN(d.getTime()) ? null : d;
    }

    const d = new Date(trimmed);
    return isNaN(d.getTime()) ? null : d;
};

const applyDateRangeFilter = (filter, startDate, endDate) => {
    const start = parseDateBoundary(startDate, false);
    const end = parseDateBoundary(endDate, true);

    if (start || end) {
        filter.createdAt = filter.createdAt || {};
        if (start) {
            filter.createdAt.$gte = start;
        }
        if (end) {
            filter.createdAt.$lte = end;
        }
    }
};

const applyCourseFilter = (filter, course) => {
    if (typeof course === "string" && course.trim()) {
        const courseList = course
            .split(",")
            .map((c) => c.trim())
            .filter(Boolean);
        if (courseList.length > 1) {
            const regexPattern = courseList
                .map((c) => escapeRegex(c.slice(0, 80)))
                .join("|");
            filter.course = { $regex: regexPattern, $options: "i" };
        } else if (courseList.length === 1) {
            filter.course = {
                $regex: escapeRegex(courseList[0].slice(0, 80)),
                $options: "i",
            };
        }
    }
};

const createContact = async (contactData) =>{
    const existingContact = await Contact.findOne({
        name:contactData.name, number:contactData.number, course:contactData.course
    })

    if(existingContact){
        throw new ApiError(409,"This Enquiry has already been submitted")
    }

    const contact = await Contact.create(contactData)
    return contact
}

const getContact = async (page=1,limit=10,search="",course="",status="",startDate="",endDate="") =>{
    const skip = (page-1)*limit

    let filter = {}

    if (typeof search === "string" && search.trim()) {
        const safeSearch = escapeRegex(search.trim().slice(0, 100));
        filter.$or = [
            { name: { $regex: safeSearch, $options: "i" } },
            { number: { $regex: safeSearch, $options: "i" } },
            { course: { $regex: safeSearch, $options: "i" } },
        ];
    }

    if (typeof status === "string" && status.trim()) {
        filter.status = { $regex: escapeRegex(status.trim().slice(0, 30)), $options: "i" };
    }

    applyCourseFilter(filter, course);

    applyDateRangeFilter(filter, startDate, endDate);

    const contacts = await Contact.find(filter).sort({createdAt:-1}).skip(skip).limit(limit)
    const total = await Contact.countDocuments(filter)

  
    return {
        contacts,
        page,
        skip,
        limit,
        total,
        totalPages:
        Math.ceil(total/limit)
    }

}



const getContactById = async (id) =>{

    if(!mongoose.Types.ObjectId.isValid(id)){
        throw new ApiError(400,"Invalid Enquiry ID")
    }


    const contact = await Contact.findById(id)
    if(!contact){
        throw new ApiError(404,"Enquiry not found")
    }
    return contact
}
const updateEnquiryStatus = async (id, status) => {

    if (!mongoose.Types.ObjectId.isValid(id)) {
        throw new ApiError(
            400,
            "Invalid Update ID"
        );
    }

    const allowedStatus = [
        "new",
        "open",
        "closed",
    ];

    if (!allowedStatus.includes(status)) {
        throw new ApiError(
            400,
            "Invalid Status. Allowed: new, Open, Closed"
        );
    }

    const contact =
        await Contact.findByIdAndUpdate(
            id,
            { status },
            {
                 
        returnDocument: "after",
        runValidators: true,
    
            }
        );

    if (!contact) {
        throw new ApiError(
            404,
            "Enquiry not Found"
        );
    }

    return contact;
};

const deleteEnquiry = async (id) => {

    if (!mongoose.Types.ObjectId.isValid(id)) {
        throw new ApiError(
            400,
            "Invalid Deleted ID"
        );
    }

    const contact =
        await Contact.findByIdAndDelete(id);

    if (!contact) {
        throw new ApiError(
            404,
            "Enquiry not Found"
        );
    }

    return contact;
};

const getAllContactsForExport = async (
    search = "",
    course = "",
    status = "",
    startDate = "",
    endDate = ""
) => {
    let filter = {};

    if (typeof search === "string" && search.trim()) {
        const safeSearch = escapeRegex(search.trim().slice(0, 100));
        filter.$or = [
            { name: { $regex: safeSearch, $options: "i" } },
            { number: { $regex: safeSearch, $options: "i" } },
            { course: { $regex: safeSearch, $options: "i" } },
        ];
    }

    if (typeof status === "string" && status.trim()) {
        filter.status = { $regex: escapeRegex(status.trim().slice(0, 30)), $options: "i" };
    }

    applyCourseFilter(filter, course);

    applyDateRangeFilter(filter, startDate, endDate);

    const contacts = await Contact.find(filter).sort({ createdAt: -1 });
    return contacts;
};

module.exports = {
    createContact,
    getContact,
    getContactById,
    updateEnquiryStatus,
    deleteEnquiry,
    getAllContactsForExport,
};