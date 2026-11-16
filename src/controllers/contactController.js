const {
    createContact,
    getContact,
    getContactById,
    updateEnquiryStatus,
    deleteEnquiry,
    getAllContactsForExport,
} = require("../services/contactService");


const submitContact = async (req,res)=>{
   
       const contact = await createContact(req.body)

        res.status(201).json({
            success:true,
            message:"Enquiry Submitted Successfully",
            data:contact
        });
}

const fetchContacts = async (req,res)=>{

    const page = Number(req.query.page) || 1;
    const limit = Number(req.query.limit) || 10
    const search = req.query.search || ""
    const course = req.query.course || ""
    const status = req.query.status || ""
    const startDate = req.query.startDate || req.query.from || ""
    const endDate = req.query.endDate || req.query.to || ""

    if(page<1){
        return res.status(400).json({
            success:false,
            message:"Page must be greater than 0"
        })

    }

    if(limit<1 || limit>100){
        return res.status(400).json({
            success:false,
            message:"Limit must be between 1 to 100"
        })
    }


    const result = await getContact(page,limit,search,course,status,startDate,endDate)

    res.status(200).json({
        success:true,
        message:"Enquiries Fetch Successfully",
        data: result.contacts,
        pagination:{
            page:result.page,
            limit:result.limit,
            total:result.total,
            totalpages:result.totalPages
        }
    })
}

const fetchContactById = async (req,res) =>{
    const contact = await getContactById(req.params.id)
    res.status(200).json({
        success:true,
        message:"Enquiry Fetch Successfully ",
        data:contact
    })
}

const updateStatus = async(req,res)=>{
    const {status} = req.body
    
    const contact = await updateEnquiryStatus(req.params.id,status)

    res.status(200).json({
        success:true,
        message:"Enquiry Updated Successfully",
        data:contact
    })
}

const deleteContact = async(req,res)=>{
    const contact = await deleteEnquiry(req.params.id)

    res.status(200).json({
        success:true,
        message:"Enquiry Deleted Successfully",
        data:contact
    })
}

const exportContacts = async (req, res) => {
    const search = req.query.search || "";
    const course = req.query.course || "";
    const status = req.query.status || "";
    const startDate = req.query.startDate || req.query.from || "";
    const endDate = req.query.endDate || req.query.to || "";

    const contacts = await getAllContactsForExport(search, course, status, startDate, endDate);

    res.status(200).json({
        success: true,
        message: "Enquiries Exported Successfully",
        data: contacts,
        total: contacts.length,
    });
};

module.exports = {
    submitContact,
    fetchContacts,
    fetchContactById,
    updateStatus,
    deleteContact,
    exportContacts,
};